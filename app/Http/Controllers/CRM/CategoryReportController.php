<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\CategorySalesPurchaseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Category Level-1 sales vs purchases.
 *
 * Lives beside the Products page because Level 1 (Chicken / Mutton /
 * Beef ...) is a product attribute, but it reads the finance side too -
 * see App\Services\CategorySalesPurchaseService for every query rule.
 */
class CategoryReportController extends Controller
{
    /**
     * Per-user preference key in t_sys_user_setting. Same generic store the
     * sidebar "Customize menu" uses (SysAdmin\UserSettingController).
     */
    private const PREF_HIDDEN = 'category_report_hidden';

    /** The single-bucket granularity backing the Summary view. */
    private const G_SUMMARY = 'total';

    private CategorySalesPurchaseService $svc;

    public function __construct(CategorySalesPurchaseService $svc)
    {
        $this->svc = $svc;
    }

    /** The report screen. */
    public function index(Request $request)
    {
        [$start, $end, $preset] = $this->resolveRange($request);
        $granularity    = $this->svc->normalizeGranularity($request->get('granularity', 'day'));
        $excludeQurbani = $request->get('qurbani', 'exclude') !== 'include';

        // Two orthogonal controls:
        //   view        - Summary (one row per category) or Detail (period breakdown)
        //   granularity - how Detail is bucketed; meaningless in Summary
        //
        // 'total' used to be a Group-by option and WAS the summary view, so an
        // old link carrying granularity=total still lands on Summary.
        $view = $request->get('view');
        if ($view === null) {
            $view = $granularity === self::G_SUMMARY ? 'summary' : 'detail';
        }
        $view = $view === 'summary' ? 'summary' : 'detail';

        // Kept separately so switching Summary -> Detail restores the bucket
        // size the user last looked at instead of snapping back to Daily.
        $detailGranularity = $granularity === self::G_SUMMARY ? 'day' : $granularity;
        $effective = $view === 'summary' ? self::G_SUMMARY : $detailGranularity;

        $hidden = $this->hiddenCategories();
        $report = $this->svc->report($start, $end, $effective, $excludeQurbani, $hidden);

        return view('pages.products.category-report', [
            'report'         => $report,
            'start'          => $start,
            'end'            => $end,
            'preset'         => $preset,
            'view'           => $view,
            'granularity'    => $detailGranularity,
            'excludeQurbani' => $excludeQurbani,
            'vocabulary'     => $this->svc->categoryVocabulary(),
            'allCategories'  => $this->svc->togglableCategories(),
            'hiddenCategories' => $hidden,
            'prefsAvailable' => $this->settingsTableReady(),
            'tagging'        => $this->svc->taggingRows(),
        ]);
    }

    /**
     * Replace the current user's hidden-category list.
     *
     * Purely a personal DISPLAY preference — it hides rows, it never
     * changes what anyone else sees and it cannot alter the underlying
     * money. Scoped to auth()->id(); a user only ever reads/writes their
     * own row.
     */
    public function saveVisibility(Request $request)
    {
        if (!$this->settingsTableReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Preferences are not set up on this server yet.',
            ]);
        }

        $input = $request->input('hidden', []);
        if (!is_array($input)) {
            $input = [];
        }

        // Only accept real category names, so a crafted or stale request
        // can't stuff junk into the preference.
        $known = $this->svc->togglableCategories();
        $clean = [];
        foreach ($input as $name) {
            if (!is_string($name)) {
                continue;
            }
            $name = trim($name);
            if ($name !== '' && in_array($name, $known, true)) {
                $clean[$name] = true;
            }
        }
        $clean = array_keys($clean);

        // Refuse to hide everything — an empty report looks like a bug.
        if (count($clean) >= count($known)) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot hide every category — keep at least one visible.',
            ], 422);
        }

        try {
            DB::table('t_sys_user_setting')->updateOrInsert(
                ['user_id' => auth()->id(), 'setting_key' => self::PREF_HIDDEN],
                ['setting_value' => json_encode($clean), 'updated_at' => now()]
            );

            return response()->json(['success' => true, 'hidden' => $clean]);
        } catch (\Throwable $e) {
            Log::error('Category visibility save failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /** The current user's hidden categories (empty when unavailable). */
    private function hiddenCategories(): array
    {
        if (!$this->settingsTableReady() || !auth()->check()) {
            return [];
        }
        try {
            $raw = DB::table('t_sys_user_setting')
                ->where('user_id', auth()->id())
                ->where('setting_key', self::PREF_HIDDEN)
                ->value('setting_value');

            $decoded = $raw ? json_decode($raw, true) : null;

            return is_array($decoded)
                ? array_values(array_filter($decoded, 'is_string'))
                : [];
        } catch (\Throwable $e) {
            Log::error('Category visibility read failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * The preference store is shared with the sidebar feature and ships
     * behind its own SQL. Guarding here means the report still works
     * (showing every category) on a server where that table is missing,
     * exactly as UserSettingController does.
     */
    private function settingsTableReady(): bool
    {
        try {
            return Schema::hasTable('t_sys_user_setting');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Vendor breakdown behind one purchase cell: which vendors (and which
     * of their products) make up the bought figure for a category in a
     * period bucket. The bucket is re-derived server-side from the same
     * range/granularity params the page was built with, clamped to the
     * range, so the drill total always equals the cell.
     */
    public function drill(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|string|max:50',
            'period'   => 'required|string|max:20',
        ]);

        try {
            [$rangeStart, $rangeEnd] = $this->resolveRange($request);
            $granularity    = $this->svc->normalizeGranularity($request->get('granularity', 'day'));
            $excludeQurbani = $request->get('qurbani', 'exclude') !== 'include';

            [$from, $to] = $this->svc->bucketBounds($validated['period'], $granularity, $rangeStart, $rangeEnd);

            return response()->json([
                'success' => true,
                'drill'   => $this->svc->purchaseDrill($from, $to->endOfDay(), $validated['category'], $excludeQurbani),
            ]);
        } catch (\Throwable $e) {
            Log::error('Category report drill failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * The freezer movements behind one cell — every packet in or out, with who
     * moved it. Same bucket maths as the purchase drill, so the detail always
     * belongs to the number that was clicked.
     */
    public function freezerDrill(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|string|max:50',
            'period'   => 'required|string|max:20',
        ]);

        try {
            [$rangeStart, $rangeEnd] = $this->resolveRange($request);
            $granularity = $this->svc->normalizeGranularity($request->get('granularity', 'day'));
            [$from, $to] = $this->svc->bucketBounds($validated['period'], $granularity, $rangeStart, $rangeEnd);

            return response()->json([
                'success' => true,
                'drill'   => $this->svc->freezerDrill($from, $to->endOfDay(), $validated['category']),
            ]);
        } catch (\Throwable $e) {
            Log::error('Category report freezer drill failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /** What was sold inside one cell, by Level-2 attribute then product. */
    public function salesDrill(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|string|max:50',
            'period'   => 'required|string|max:20',
        ]);

        try {
            [$rangeStart, $rangeEnd] = $this->resolveRange($request);
            $granularity    = $this->svc->normalizeGranularity($request->get('granularity', 'day'));
            $excludeQurbani = $request->get('qurbani', 'exclude') !== 'include';
            [$from, $to] = $this->svc->bucketBounds($validated['period'], $granularity, $rangeStart, $rangeEnd);

            return response()->json([
                'success' => true,
                'drill'   => $this->svc->salesDrill($from, $to->endOfDay(), $validated['category'], $excludeQurbani),
            ]);
        } catch (\Throwable $e) {
            Log::error('Category report sales drill failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Set the Level-1 category on ONE vendor product, or a vendor's
     * default. Both are plain tags - nothing recalculates, the report
     * simply resolves them at read time.
     */
    public function saveTag(Request $request)
    {
        $validated = $request->validate([
            'scope'    => 'required|in:product,vendor',
            'id'       => 'required|integer',
            'category' => 'nullable|string|max:50',
        ]);

        $category = trim((string) ($validated['category'] ?? ''));
        $category = $category === '' ? null : $category;

        // Only accept values from the real vocabulary, so the purchase
        // side can never drift away from the sales side by a typo.
        if ($category !== null && !in_array($category, $this->svc->categoryVocabulary(), true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unknown category: ' . $category,
            ], 422);
        }

        try {
            if ($validated['scope'] === 'product') {
                $ok = DB::table('t_fin_vendor_products')
                    ->where('id', $validated['id'])
                    ->update(['category_level_1' => $category, 'updated_at' => now()]);
            } else {
                $ok = DB::table('t_fin_vendors')
                    ->where('id', $validated['id'])
                    ->update(['default_category_level_1' => $category, 'updated_at' => now()]);
            }

            return response()->json([
                'success'  => true,
                'message'  => $category === null ? 'Category cleared' : 'Tagged as ' . $category,
                'changed'  => (int) $ok,
            ]);
        } catch (\Throwable $e) {
            Log::error('Category tag save failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =================================================================

    /**
     * Turn the request into a concrete window.
     *
     * Presets are anchored on the business week: it starts WEDNESDAY and
     * runs to Monday night. The bucket itself is a full Wed->Tue span
     * (Tuesday is quiet but not empty, and dropping it would lose money
     * from the report) - see the service for that rule.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolveRange(Request $request): array
    {
        $preset = $request->get('preset', 'last_30');
        $today  = Carbon::today();

        switch ($preset) {
            case 'today':
                return [$today->copy(), $today->copy(), $preset];

            case 'yesterday':
                $d = $today->copy()->subDay();
                return [$d, $d->copy(), $preset];

            case 'this_week':
                return [$this->weekStart($today), $today->copy(), $preset];

            case 'last_week':
                $s = $this->weekStart($today)->subDays(7);
                return [$s, $s->copy()->addDays(6), $preset];

            case 'this_month':
                return [$today->copy()->startOfMonth(), $today->copy(), $preset];

            case 'last_month':
                $s = $today->copy()->subMonthNoOverflow()->startOfMonth();
                return [$s, $s->copy()->endOfMonth(), $preset];

            case 'last_7':
                return [$today->copy()->subDays(6), $today->copy(), $preset];

            case 'last_90':
                return [$today->copy()->subDays(89), $today->copy(), $preset];

            case 'custom':
                $s = $this->parseDate($request->get('start'), $today->copy()->subDays(29));
                $e = $this->parseDate($request->get('end'), $today->copy());
                if ($e->lt($s)) {
                    [$s, $e] = [$e, $s];
                }
                return [$s, $e, 'custom'];

            case 'last_30':
            default:
                return [$today->copy()->subDays(29), $today->copy(), 'last_30'];
        }
    }

    /** Wednesday on/before $d. */
    private function weekStart(Carbon $d): Carbon
    {
        // Carbon: 0=Sun..6=Sat, so Wednesday = 3.
        $offset = ($d->dayOfWeek - Carbon::WEDNESDAY + 7) % 7;
        return $d->copy()->subDays($offset)->startOfDay();
    }

    private function parseDate($value, Carbon $fallback): Carbon
    {
        try {
            return $value ? Carbon::parse($value)->startOfDay() : $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }
}
