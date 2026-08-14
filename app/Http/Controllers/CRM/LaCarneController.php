<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\CRM\LaCarneAccessService;
use App\Services\CRM\OvernightStockService;
use App\Services\CRM\OrderStatusRuleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * 🐔 LA CARNE — the chicken board.
 *
 * La Carne is a chicken SUPPLIER. The team opens this while standing at the
 * supplier deciding how much to buy, so the screen answers three questions in
 * one glance:
 *   1. what do we still owe customers   (open + pending)
 *   2. what is on bikes right now       (out for delivery)
 *   3. what already went out            (delivered on the chosen date)
 * plus what is already in our own chiller/freezer, and the photographed
 * supplier invoice for that date.
 *
 * ── DESIGN NOTES worth keeping ────────────────────────────────────────────
 *
 * ⭐⭐ "Chicken" is t_crm_prod_product.attribute_1 — Category Level 1. There is
 *     no category table in this system; the three attribute_* columns ARE the
 *     levels. The value is read from config (t_fin_config.LACARNE_CATEGORY)
 *     with a literal fallback, so renaming the category never needs a deploy.
 *
 * ⭐⭐ THE DATE ONLY DRIVES "DELIVERED" (and the photos). Open and
 *     out-for-delivery are LIVE state — an order is open *now*, it was not
 *     "open on the 4th" — so those two sections are returned only when the
 *     caller is looking at today. Picking a past date shows Delivered alone,
 *     which is exactly what the owner asked for.
 *
 * ⚠⚠ This deliberately does NOT copy Open Quantities' `preparation_status !=
 *     'preparing'` filter. Going out-for-delivery auto-marks items prepared
 *     (OrderStatusRuleService::outTheDoor), which is precisely why that screen
 *     shows no OFD volume. Here the OFD section must show the real quantity,
 *     so no preparation filter is applied anywhere.
 *
 * ⚠ The product join is copied verbatim from OrderController::openQuantitiesData
 *   — it is EXCLUSIVE (sku wins; id fallbacks only when there is no sku) to
 *   stop one line item matching several variants and multiplying the SUMs.
 *
 * Dual-mounted: routes/web.php (prefix 'lacarne') and routes/api.php
 * (prefix 'rider/store/lacarne', auth:sanctum) share every method below, so
 * the web page and the phone can never disagree.
 */
class LaCarneController extends Controller
{
    /** Fallback when t_fin_config has no row (or no table). */
    private const DEFAULT_CATEGORY = 'Chicken';

    /**
     * How far back to look for OPEN work. Matches the Open Quantities screen
     * so the two boards tell the same story. Anything older is surfaced as a
     * "stale" count rather than silently dropped — see notices().
     */
    private const OPEN_WINDOW_DAYS = 20;

    private const PHOTO_TABLE = 't_crm_lacarne_invoice_photo';
    private const MAX_PHOTOS = 8;
    private const MAX_PHOTO_KB = 8192;

    // =================================================================
    // access
    // =================================================================

    /**
     * @var array<string,array> memoised access decisions, keyed by user + date.
     *
     * ⚠ Keyed by USER, not just cached once: Laravel builds a fresh controller
     *   per request so one instance normally serves one person, but a memo that
     *   silently answered for whoever asked first is a nasty thing to leave
     *   lying around (a test harness reusing the instance across actors caught
     *   exactly that). Keying it costs nothing and removes the trap.
     */
    private array $accessCache = [];

    /**
     * THE access decision, from the one authority (LaCarneAccessService):
     * either the store team's `access_lacarne` permission (any date), or a
     * rider rostered at a La Carne location today (that day only).
     *
     * Memoised per request — several gates ask, and the roster path resolves a
     * shift. The requested date never changes within one request.
     */
    private function access(?string $requestedDate = null): array
    {
        $key = (string) (auth()->id() ?? 0) . '|' . (string) $requestedDate;

        if (!isset($this->accessCache[$key])) {
            $this->accessCache[$key] = app(LaCarneAccessService::class)
                ->forUser(auth()->user(), $requestedDate);
        }

        return $this->accessCache[$key];
    }

    private function hasAccess(): bool
    {
        return $this->access()['allowed'];
    }

    /**
     * The date this caller is actually allowed to look at.
     *
     * ⚠ A rostered rider is pinned to TODAY (owner ruling). Rather than 403 on
     *   a stale request, the date is clamped — the phone hides the arrows, and
     *   an out-of-date client simply sees today instead of an error it cannot
     *   explain to the user.
     */
    private function allowedDate(?string $requested): string
    {
        $access = $this->access($requested);

        return $access['scope'] === LaCarneAccessService::SCOPE_ALL
            ? $this->safeDate($requested)
            : $access['today'];
    }

    /** May this person touch photos on a date BEFORE today? */
    private function canManageHistory(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }
        if (!$user->relationLoaded('roles')) {
            $user->load(['roles.mobilePermissions']);
        }

        return $user->hasMobilePermission('manage_lacarne_history');
    }

    /**
     * THE write rule, in one place, used by the endpoints AND by the flags the
     * UIs render from — so a hidden button and a refused request can never
     * disagree.
     *
     * ⚠ Read-only accounts are refused outright: ReadOnlyGuard blocks most
     *   writes already, but this endpoint is reachable under Sanctum too.
     */
    private function canEditDate(string $ymd): bool
    {
        $user = auth()->user();
        if (!$user || !$this->hasAccess()) {
            return false;
        }
        if (method_exists($user, 'isReadOnly') && $user->isReadOnly()) {
            return false;
        }

        $today = Carbon::today()->format('Y-m-d');

        // A rostered rider is the person actually holding the supplier's
        // invoice, so they may photograph TODAY's — but never an earlier date,
        // whatever history rights they might otherwise have.
        if ($this->access()['via'] === LaCarneAccessService::VIA_ROSTER) {
            return $ymd === $today;
        }

        return $ymd === $today || $this->canManageHistory();
    }

    /**
     * Deleting is narrower than adding for a rostered rider: they may remove a
     * photo THEY uploaded today (a blurred shot, the wrong invoice), never
     * somebody else's. The store team keeps the original rule.
     */
    private function canDeletePhoto(object $row): bool
    {
        $date = substr((string) $row->photo_date, 0, 10);

        if (!$this->canEditDate($date)) {
            return false;
        }

        if ($this->access()['via'] === LaCarneAccessService::VIA_ROSTER) {
            return (int) ($row->uploaded_by ?? 0) === (int) auth()->id();
        }

        return true;
    }

    private function denyJson(string $message = 'You do not have access to La Carne.')
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }

    // =================================================================
    // pages
    // =================================================================

    /** The web page shell — every number arrives from board() by AJAX. */
    public function index(Request $request)
    {
        if (!$this->hasAccess()) {
            return redirect()->route('dashboard')
                ->with('error', 'You do not have permission to view La Carne.');
        }

        return view('pages.lacarne.index', [
            'category' => $this->category(),
            'today' => Carbon::today()->format('Y-m-d'),
        ]);
    }

    /**
     * "May I see La Carne right now, and why?" — a deliberately tiny endpoint.
     *
     * The rider app polls this to decide whether to show the La Carne TAB, so it
     * must stay cheap: one cached shift resolution and nothing else. It is what
     * makes the tab appear when a rider is put on the La Carne roster and vanish
     * when they are taken off, WITHOUT a re-login — the mobile permission list is
     * only fetched at app start and at login, so it could never do this on its own.
     */
    public function access_check(Request $request)
    {
        $access = $this->access();

        return response()->json([
            'success' => true,
            'allowed' => $access['allowed'],
            'via' => $access['via'],
            'scope' => $access['scope'],
            'can_change_date' => $access['can_change_date'],
            'location_name' => $access['location_name'],
            'date' => $access['date'],
        ]);
    }

    // =================================================================
    // the one data endpoint
    // =================================================================

    /**
     * Everything the screen needs for one date in ONE response: the three
     * sections (each a full drill tree), the chiller/freezer chips, the
     * invoice photos and the caller's own rights.
     *
     * One category on one day is a small payload, so the phone drills through
     * the tree it already has instead of making a request per node. That also
     * side-steps the three separate node-key whitelists the Open Quantities
     * tree has to pass through.
     */
    public function board(Request $request)
    {
        if (!$this->hasAccess()) {
            return $this->denyJson();
        }

        try {
            $access = $this->access($request->get('date'));
            $date = $this->allowedDate($request->get('date'));
            $isToday = $date === Carbon::today()->format('Y-m-d');
            $category = $this->category();

            $stock = app(OvernightStockService::class);
            // Stock is what is in the chiller RIGHT NOW. There is no historical
            // snapshot anywhere in the system, so showing it against a past date
            // would be quietly wrong — it is attached for today only.
            $stockMap = $isToday ? $stock->map() : [];
            $stockCatalog = $isToday ? $stock->catalog() : [];

            $buckets = $this->bucketStatuses();
            $sections = [];

            if ($isToday) {
                $openRows = $this->lineItems($category, 'open');
                $openSection = $this->section(
                    'open',
                    'Pending & open',
                    $openRows,
                    $category,
                    $stockMap,
                    $stockCatalog
                );
                $openSection['statuses'] = $buckets['open'];
                $sections[] = $openSection;

                $ofdRows = $this->lineItems($category, 'ofd');
                $ofdSection = $this->section(
                    'out_for_delivery',
                    'Out for delivery',
                    $ofdRows,
                    $category,
                    $stockMap,
                    $stockCatalog
                );
                $ofdSection['statuses'] = $buckets['ofd'];
                // R5: how many of those are actually on the road. "Dispatched"
                // is eta_calculated_at IS NOT NULL — the same test Dispatch Next
                // uses, and every un-dispatch path already clears it. Kept as one
                // summary number rather than a badge per row.
                $ofdSection['summary']['dispatched_orders'] = $this->dispatchedCount($ofdRows);
                $sections[] = $ofdSection;
            }

            $deliveredRows = $this->lineItems($category, 'delivered', $date);
            $deliveredSection = $this->section(
                'delivered',
                'Delivered',
                $deliveredRows,
                $category,
                $stockMap,
                $stockCatalog
            );
            $deliveredSection['statuses'] = ['delivered'];
            $sections[] = $deliveredSection;

            return response()->json([
                'success' => true,
                'date' => $date,
                'is_today' => $isToday,
                'category' => $category,
                'window_days' => self::OPEN_WINDOW_DAYS,
                'sections' => $sections,
                'storage' => $isToday
                    ? $stock->sumForCategory($stockCatalog, ['attribute_1' => $category])
                    : null,
                // Per-product breakdown behind the storage card. Carries each item's
                // attribute path and product id so the UI can narrow it to whatever
                // the user has drilled into, without another request.
                'storage_items' => $isToday ? $this->storageItems($category, $stockCatalog) : [],
                'photos' => $this->photosFor($date),
                'can_edit_photos' => $this->canEditDate($date),
                'can_manage_history' => $this->canManageHistory(),
                'notices' => $this->notices($category, $date, $isToday),
                // How this caller got in, so the UI can pin the date stepper and
                // say why. 'roster' = a rider on shift at La Carne today.
                'access' => [
                    'via' => $access['via'],
                    'scope' => $access['scope'],
                    'can_change_date' => $access['can_change_date'],
                    'location_name' => $access['location_name'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('La Carne board failed', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not load the La Carne board: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =================================================================
    // photos
    // =================================================================

    /**
     * Add one or more invoice photos to a date.
     *
     * Anyone with La Carne access may photograph TODAY's invoice; changing an
     * earlier date additionally needs manage_lacarne_history. That check is
     * server-side and authoritative — the phone hides the button using the same
     * rule, but hiding a button is not a permission.
     */
    public function addPhotos(Request $request)
    {
        if (!$this->hasAccess()) {
            return $this->denyJson();
        }
        if (!$this->photosAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice photos are not set up yet (run LACARNE-AUG13-2026.sql).',
            ], 422);
        }

        $request->validate([
            'photos' => 'required|array|max:' . self::MAX_PHOTOS,
            'photos.*' => 'image|max:' . self::MAX_PHOTO_KB,
            'date' => 'nullable|date',
            'note' => 'nullable|string|max:255',
        ]);

        $date = $this->allowedDate($request->input('date'));

        if (!$this->canEditDate($date)) {
            return $this->denyJson(
                'Only a manager can change an invoice from an earlier date. '
                . 'Ask for the La Carne history right, or photograph it against today.'
            );
        }

        $note = $request->input('note');
        $note = is_string($note) && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null;

        $saved = 0;
        $failed = 0;

        // Best effort, one file at a time: a single bad JPEG must not throw away
        // the pages that did upload.
        foreach ((array) $request->file('photos') as $file) {
            try {
                if (!$file || !$file->isValid()) {
                    $failed++;
                    continue;
                }
                $path = $this->storeOne($file, $date);
                DB::table(self::PHOTO_TABLE)->insert([
                    'photo_date' => $date,
                    'photo_path' => $path,
                    'note' => $note,
                    'uploaded_by' => (int) auth()->id(),
                    'created_at' => now(),
                ]);
                $saved++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('La Carne photo upload failed', [
                    'date' => $date,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $message = $saved && !$failed
            ? $saved . ' photo' . ($saved === 1 ? '' : 's') . ' saved.'
            : ($saved
                ? $saved . ' saved, ' . $failed . ' could not be uploaded.'
                : 'Nothing could be uploaded — try again.');

        return response()->json([
            'success' => $saved > 0,
            'message' => $message,
            'date' => $date,
            // Hand back the refreshed strip so the caller never has to re-fetch
            // and the two can never disagree about what was just saved.
            'photos' => $this->photosFor($date),
            'can_edit_photos' => $this->canEditDate($date),
        ], $saved > 0 ? 200 : 422);
    }

    /**
     * Remove one photo. "Modify" in this feature means delete + add again,
     * matching every other photo surface in the system.
     *
     * ⚠ The date gate is read from the ROW, not from the request — otherwise a
     *   caller could delete last week's invoice by claiming the date is today.
     */
    public function deletePhoto(Request $request, $photoId)
    {
        if (!$this->hasAccess()) {
            return $this->denyJson();
        }
        if (!$this->photosAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice photos are not set up yet (run LACARNE-AUG13-2026.sql).',
            ], 422);
        }

        $row = DB::table(self::PHOTO_TABLE)->where('id', (int) $photoId)->first();
        if (!$row) {
            return response()->json(['success' => false, 'message' => 'That photo is already gone.'], 404);
        }

        $date = substr((string) $row->photo_date, 0, 10);

        if (!$this->canDeletePhoto($row)) {
            return $this->denyJson(
                $this->access()['via'] === LaCarneAccessService::VIA_ROSTER
                    ? 'You can only remove a photo you took yourself today.'
                    : 'Only a manager can remove an invoice photo from an earlier date.'
            );
        }

        try {
            DB::table(self::PHOTO_TABLE)->where('id', (int) $photoId)->delete();
        } catch (\Throwable $e) {
            Log::error('La Carne photo delete failed', ['id' => $photoId, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Could not remove that photo.'], 500);
        }

        // The FILE is deliberately left on disk — same as vehicle photos. It is
        // cheap, and it makes an accidental delete recoverable by hand.
        return response()->json([
            'success' => true,
            'message' => 'Photo removed.',
            'date' => $date,
            'photos' => $this->photosFor($date),
            'can_edit_photos' => $this->canEditDate($date),
        ]);
    }

    // =================================================================
    // queries
    // =================================================================

    /**
     * ⭐⭐ WHICH STATUSES BELONG IN WHICH SECTION — and why this does NOT simply
     *     reuse OrderStatusRuleService::quantitiesExcluded().
     *
     *  On this system that excluded list is ["cancelled","refunded","pending",
     *  "dispatch"] — it does NOT contain delivered or completed. Open Quantities
     *  gets away with that because it ALSO filters out line items marked
     *  `preparation_status = 'preparing'`, and going out the door auto-marks
     *  items prepared; that filter is what actually hides finished work there.
     *  This screen deliberately drops that filter (otherwise the out-for-delivery
     *  section would read zero), so reusing the excluded list alone would have
     *  quietly counted every delivered order as "open" — on the replica that was
     *  12,885 delivered orders leaking into the open bucket.
     *
     *  So the three sections are derived from the Status Hub's OWN flags:
     *    dead = is_final AND lane 'offtrack'   → cancelled, refunded
     *    gone = auto_prepares = 1              → has physically left the store
     *    done = delivered / completed          → the finished vocabulary used
     *                                            system-wide (revenue, delivery
     *                                            date, customer app all key on it)
     *  giving:  out-for-delivery = gone − done      (out_for_delivery, on_van, dispatch)
     *           open             = everything − dead − gone
     *
     *  Every live status therefore lands in exactly one section — nothing is
     *  silently dropped, which is the failure mode that matters on a screen used
     *  to decide how much to buy.
     */
    private function bucketStatuses(): array
    {
        $done = ['delivered', 'completed'];
        $fallback = [
            'dead' => ['cancelled', 'refunded'],
            'ofd' => ['out_for_delivery'],
            'open' => ['new', 'pending', 'on_hold', 'on-hold', 'priority', 'processing'],
        ];

        try {
            if (!Schema::hasTable('t_crm_order_status_master')) {
                return $fallback;
            }

            $rows = DB::table('t_crm_order_status_master')->where('is_active', 1)->get();
            if ($rows->isEmpty()) {
                return $fallback;
            }

            // "Has left the store" already has ONE authority — the Status Hub rule
            // service (auto_prepares, cached, with its own literal fallback). Reuse
            // it rather than growing a second reading of the same column.
            $gone = app(OrderStatusRuleService::class)->outTheDoor();

            $hasLane = Schema::hasColumn('t_crm_order_status_master', 'lane');
            $dead = [];
            $all = [];

            foreach ($rows as $row) {
                $code = (string) $row->status_code;
                $all[] = $code;

                $isOfftrack = $hasLane ? ((string) ($row->lane ?? '') === 'offtrack') : false;
                if (!empty($row->is_final) && $isOfftrack) {
                    $dead[] = $code;
                }
            }

            if (empty($dead)) {
                $dead = $fallback['dead'];
            }
            if (empty($gone)) {
                $gone = array_merge($fallback['ofd'], $done);
            }

            return [
                'dead' => array_values(array_unique($dead)),
                'ofd' => array_values(array_diff($gone, $done)),
                'open' => array_values(array_diff($all, $dead, $gone)),
            ];
        } catch (\Throwable $e) {
            Log::warning('La Carne: status master unreadable, using literals', ['error' => $e->getMessage()]);

            return $fallback;
        }
    }

    /**
     * Flat line-item rows for one bucket. The tree is assembled in PHP
     * afterwards — one category on one day is a small result set, and the
     * readable version is worth far more here than a clever single-pass SQL.
     *
     * $bucket: open | ofd | delivered
     */
    private function lineItems(string $category, string $bucket, ?string $date = null)
    {
        $sets = $this->categorySets($category);
        if (empty($sets['any'])) {
            // Nothing is classified under this category — no query can match.
            return collect();
        }

        $buckets = $this->bucketStatuses();

        $query = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id');

        // ⭐ PERFORMANCE, not semantics. Narrow the line items to ones that could
        //   possibly resolve to this category BEFORE the expensive variant/product
        //   join runs (its name-fallback arm compares LOWER(TRIM(...)) and cannot
        //   use an index). This is a strict SUPERSET of what the join + category
        //   WHERE can match — every join path is represented — so results are
        //   identical; only the work is smaller. Board time went 21.6s → well under
        //   a second on the replica.
        $this->applyCategoryPrefilter($query, $sets);

        if ($bucket === 'delivered') {
            // ⭐ The delivery DATE lives in the status history, never on the order
            //   row (OrderModel::delivery_date is an accessor, not a column).
            //   MIN(id) picks ONE 'delivered' row per order, so an order marked
            //   delivered twice cannot be counted twice. is_current is deliberately
            //   NOT filtered — delivered → completed flips it to 0.
            $firstDelivered = DB::table('t_crm_order_status_history')
                ->select('order_id', DB::raw('MIN(id) as first_delivered_id'))
                ->where('status_code', 'delivered')
                ->whereBetween('changed_at', [$date . ' 00:00:00', $date . ' 23:59:59'])
                ->groupBy('order_id');

            $query->joinSub($firstDelivered, 'fd', fn ($j) => $j->on('o.id', '=', 'fd.order_id'));
        }

        $this->joinProduct($query);

        $query->where(function ($q) {
            $q->where('o.external_source', '!=', 'shopify')
              ->orWhereNull('o.external_source');
        });

        // ⭐ THE chicken filter. Case-insensitive because the value is
        //   owner-editable data, not a constant.
        $query->whereRaw('LOWER(TRIM(p.attribute_1)) = ?', [mb_strtolower(trim($category))]);

        if ($bucket === 'open') {
            $query->whereIn('o.order_status', $buckets['open'])
                  ->where('o.order_date', '>=', Carbon::now()->subDays(self::OPEN_WINDOW_DAYS));
        } elseif ($bucket === 'ofd') {
            // No date window: an order that left the store is outstanding no
            // matter how old the order is, and there are only ever a handful.
            $query->whereIn('o.order_status', $buckets['ofd']);
        }

        return $query
            ->leftJoin('t_crm_prod_customer as c', 'o.customer_id', '=', 'c.id')
            ->select([
                'o.id as order_id',
                'o.order_number',
                'o.order_status',
                'o.order_date',
                'o.eta_calculated_at',
                DB::raw('COALESCE(
                    NULLIF(TRIM(o.name), ""),
                    NULLIF(TRIM(CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, ""))), ""),
                    NULLIF(TRIM(CONCAT(COALESCE(o.address_first_name, ""), " ", COALESCE(o.address_last_name, ""))), ""),
                    "Customer"
                ) as customer_name'),
                'p.id as product_id',
                DB::raw('COALESCE(NULLIF(p.attribute_2, ""), "Uncategorized") as attr2'),
                DB::raw('COALESCE(NULLIF(p.attribute_3, ""), "Uncategorized") as attr3'),
                DB::raw('COALESCE(NULLIF(p.title, ""), li.name) as product_name'),
                'li.quantity',
                DB::raw('(li.quantity * COALESCE(p.unit_weight_kg, 1)) as line_weight'),
            ])
            ->get();
    }

    /**
     * The EXCLUSIVE product join, copied from OrderController::openQuantitiesData.
     *
     * ⚠ Do not "simplify" this into plain ORs. SKU wins when present; the
     *   variant/product id fallbacks apply ONLY when there is no SKU. Without
     *   that exclusivity one line item matches several variant rows and every
     *   SUM in this file inflates.
     */
    private function joinProduct($query): void
    {
        $query->leftJoin('t_crm_prod_product_variant as pv', function ($join) {
            $join->where(function ($q) {
                $q->where(function ($skuMatch) {
                    $skuMatch->whereNotNull('li.sku')
                             ->where('li.sku', '!=', '')
                             ->whereColumn('li.sku', 'pv.sku');
                })
                ->orWhere(function ($fallback) {
                    $fallback->where(function ($noSku) {
                        $noSku->whereNull('li.sku')
                              ->orWhere('li.sku', '');
                    })
                    ->where(function ($idMatch) {
                        $idMatch->whereColumn('li.variant_id', 'pv.shopify_variant_id')
                                ->orWhereColumn('li.variant_id', 'pv.id')
                                ->orWhereColumn('li.product_id', 'pv.shopify_variant_id')
                                ->orWhereColumn('li.product_id', 'pv.id');
                    });
                });
            });
        })
        ->leftJoin('t_crm_prod_product as p', function ($join) {
            $join->where(function ($q) {
                $q->whereColumn('pv.product_id', 'p.id')
                  ->orWhere(function ($nameFallback) {
                      $nameFallback->whereNull('li.sku')
                                   ->where(function ($noIds) {
                                       $noIds->whereNull('li.variant_id')
                                             ->orWhere('li.variant_id', '');
                                   })
                                   ->where(function ($noProdId) {
                                       $noProdId->whereNull('li.product_id')
                                                ->orWhere('li.product_id', '');
                                   })
                                   ->whereRaw('LOWER(TRIM(li.name)) = LOWER(TRIM(p.title))');
                  });
            });
        });
    }

    /**
     * Everything a line item could be matched by, for products in this category:
     * their skus, their variant ids (internal AND shopify), their product ids,
     * and their lowercased titles.
     *
     * Small by construction — one level-1 category is ~80 products / ~80 variants
     * on production — and memoised for the request.
     */
    private function categorySets(string $category): array
    {
        // Keyed by category, not a bare flag: the memo must never answer for a
        // different category than the one asked for.
        $key = mb_strtolower(trim($category));
        if (isset($this->categorySetsCache[$key])) {
            return $this->categorySetsCache[$key];
        }

        $out = ['skus' => [], 'ids' => [], 'titles' => [], 'any' => false];

        try {
            $products = DB::table('t_crm_prod_product')
                ->whereRaw('LOWER(TRIM(attribute_1)) = ?', [mb_strtolower(trim($category))])
                ->get(['id', 'title']);

            if ($products->isEmpty()) {
                return $this->categorySetsCache[$key] = $out;
            }

            $productIds = $products->pluck('id')->map(fn ($v) => (int) $v)->all();
            foreach ($products as $p) {
                $title = mb_strtolower(trim((string) $p->title));
                if ($title !== '') {
                    $out['titles'][] = $title;
                }
            }

            $variants = DB::table('t_crm_prod_product_variant')
                ->whereIn('product_id', $productIds)
                ->get(['id', 'sku', 'shopify_variant_id']);

            foreach ($variants as $v) {
                $sku = trim((string) $v->sku);
                if ($sku !== '') {
                    $out['skus'][] = $sku;
                }
                if (!empty($v->id)) {
                    $out['ids'][] = (int) $v->id;
                }
                if (!empty($v->shopify_variant_id)) {
                    $out['ids'][] = (int) $v->shopify_variant_id;
                }
            }

            // li.product_id can also carry an internal product id on manual orders.
            $out['ids'] = array_merge($out['ids'], $productIds);

            $out['skus'] = array_values(array_unique($out['skus']));
            $out['ids'] = array_values(array_unique(array_filter($out['ids'], fn ($v) => $v > 0)));
            $out['titles'] = array_values(array_unique($out['titles']));
            $out['any'] = !empty($out['skus']) || !empty($out['ids']) || !empty($out['titles']);
        } catch (\Throwable $e) {
            Log::warning('La Carne category sets unavailable', ['error' => $e->getMessage()]);
        }

        return $this->categorySetsCache[$key] = $out;
    }

    /** @var array<string,array> memoised categorySets() results, keyed by category */
    private array $categorySetsCache = [];

    /**
     * The superset pre-filter described in lineItems(). Each arm mirrors one arm
     * of the product join, minus the join's "only when there is no sku"
     * conditions — dropping those can only WIDEN the set, never narrow it, which
     * is what makes this safe.
     */
    private function applyCategoryPrefilter($query, array $sets): void
    {
        $query->where(function ($q) use ($sets) {
            $touched = false;

            if (!empty($sets['skus'])) {
                $q->whereIn('li.sku', $sets['skus']);
                $touched = true;
            }
            if (!empty($sets['ids'])) {
                $ids = $sets['ids'];
                $arm = fn ($w) => $w->whereIn('li.variant_id', $ids)->orWhereIn('li.product_id', $ids);
                $touched ? $q->orWhere($arm) : $q->where($arm);
                $touched = true;
            }
            if (!empty($sets['titles'])) {
                $placeholders = implode(',', array_fill(0, count($sets['titles']), '?'));
                $raw = "LOWER(TRIM(li.name)) IN ($placeholders)";
                $touched ? $q->orWhereRaw($raw, $sets['titles']) : $q->whereRaw($raw, $sets['titles']);
            }
        });
    }

    /**
     * The stored packets behind the storage card, one row per product per section.
     *
     * Built from the stock catalogue the sections already loaded — no extra stock
     * query — plus one cheap indexed lookup for the titles. Deliberately does NOT
     * change OvernightStockService: that service is shared with both Quantities
     * screens, and product titles are a display concern of this page alone.
     *
     * ⚠ Blank attributes are labelled 'Uncategorized' exactly as the tree labels
     *   them, so the client can match an item against a drill path by string.
     */
    private function storageItems(string $category, array $catalog): array
    {
        if (empty($catalog)) {
            return [];
        }

        $lower = mb_strtolower(trim($category));
        $wanted = [];
        foreach ($catalog as $entry) {
            if (mb_strtolower(trim((string) ($entry['attribute_1'] ?? ''))) === $lower) {
                $wanted[(int) $entry['id']] = $entry;
            }
        }
        if (empty($wanted)) {
            return [];
        }

        try {
            $titles = DB::table('t_crm_prod_product')
                ->whereIn('id', array_keys($wanted))
                ->pluck('title', 'id');
        } catch (\Throwable $e) {
            $titles = collect();
        }

        $label = fn ($v) => (($v === null || trim((string) $v) === '') ? 'Uncategorized' : (string) $v);

        $rows = [];
        foreach ($wanted as $pid => $entry) {
            foreach (OvernightStockService::SECTIONS as $section) {
                $t = $entry['totals'][$section] ?? null;
                if (empty($t) || $t['packets'] <= 0) {
                    continue;   // nothing of this product in this section
                }
                $rows[] = [
                    'product_id' => $pid,
                    'product_name' => (string) ($titles[$pid] ?? ('Product #' . $pid)),
                    'section' => $section,
                    'packets' => (int) $t['packets'],
                    'kg' => round((float) $t['kg'], 3),
                    'pcs' => round((float) $t['pcs'], 3),
                    'attribute_2' => $label($entry['attribute_2'] ?? null),
                    'attribute_3' => $label($entry['attribute_3'] ?? null),
                ];
            }
        }

        // Chiller first (it is the stock you would use today), then biggest first.
        usort($rows, function ($a, $b) {
            if ($a['section'] !== $b['section']) {
                return $a['section'] === 'chiller' ? -1 : 1;
            }
            return ($b['kg'] + $b['pcs']) <=> ($a['kg'] + $a['pcs']);
        });

        return $rows;
    }

    /** How many DISTINCT orders in this bucket have actually been dispatched. */
    private function dispatchedCount($rows): int
    {
        $seen = [];
        foreach ($rows as $row) {
            if (!empty($row->eta_calculated_at)) {
                $seen[$row->order_id] = true;
            }
        }

        return count($seen);
    }

    /**
     * Things the numbers above cannot say for themselves. Never silently drop
     * work: if something is outside the window, or a product is not classified,
     * say so rather than showing a confident total that is quietly short.
     */
    /**
     * ⚠ Deliberately does NOT report products missing a Category Level 1.
     *   The owner's call (Aug-13): an uncategorised product is a catalogue problem
     *   the team already handles, and surfacing it on the buying screen is noise
     *   at the moment of a decision. Do not add it back without asking.
     */
    private function notices(string $category, string $date, bool $isToday): array
    {
        $out = ['stale_open_orders' => 0];

        try {
            $sets = $this->categorySets($category);
            $buckets = $this->bucketStatuses();

            if (!empty($sets['any'])) {
                $q = DB::table('t_crm_prod_order_line_item as li')
                    ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id');
                $this->applyCategoryPrefilter($q, $sets);
                $this->joinProduct($q);

                $q->where(function ($w) {
                    $w->where('o.external_source', '!=', 'shopify')->orWhereNull('o.external_source');
                })
                ->whereRaw('LOWER(TRIM(p.attribute_1)) = ?', [mb_strtolower(trim($category))])
                ->whereIn('o.order_status', $buckets['open']);

                if ($isToday) {
                    // Open work OLDER than the window: real outstanding demand the
                    // sections above cannot show. Small and worth seeing — these
                    // are usually orders that were never closed off.
                    $q->where('o.order_date', '<', Carbon::now()->subDays(self::OPEN_WINDOW_DAYS));
                } else {
                    // On a past date: orders placed that day that never closed. The
                    // owner expects none, which is precisely why one matters.
                    $q->whereRaw('DATE(o.order_date) = ?', [$date]);
                }

                $out['stale_open_orders'] = (int) $q->distinct()->count('o.id');
            }
        } catch (\Throwable $e) {
            Log::warning('La Carne notices unavailable', ['error' => $e->getMessage()]);
        }

        return $out;
    }

    // =================================================================
    // tree building
    // =================================================================

    /**
     * One section = a summary plus a drill tree, four levels deep:
     *   Category level 2 → Category level 3 → product → orders.
     *
     * ⭐ The levels are FIXED here rather than read from
     *   t_crm_open_quantities_settings.hierarchy_levels. That row is shared by
     *   the web and mobile Quantities screens and reorganising it is disruptive;
     *   binding a third screen to it would spread the same split-brain risk.
     *   Level 1 is pinned to the category, so only the levels below it matter.
     */
    private function section(
        string $key,
        string $title,
        $rows,
        string $category,
        array $stockMap,
        array $stockCatalog
    ): array {
        $tree = [];
        $orderIds = [];
        $totalQty = 0.0;
        $totalWeight = 0.0;

        foreach ($rows as $row) {
            $qty = (float) $row->quantity;
            $weight = (float) $row->line_weight;
            $attr2 = (string) $row->attr2;
            $attr3 = (string) $row->attr3;
            $productId = $row->product_id !== null ? (int) $row->product_id : 0;
            $productKey = $productId . '|' . $row->product_name;

            $totalQty += $qty;
            $totalWeight += $weight;
            $orderIds[$row->order_id] = true;

            // level 1 — category level 2
            if (!isset($tree[$attr2])) {
                $tree[$attr2] = $this->blankNode($attr2, 'attribute_2');
                $tree[$attr2]['_path'] = ['attribute_1' => $category, 'attribute_2' => $attr2];
            }
            $this->addTo($tree[$attr2], $qty, $weight, $row->order_id, $productId);

            // level 2 — category level 3
            if (!isset($tree[$attr2]['children'][$attr3])) {
                $tree[$attr2]['children'][$attr3] = $this->blankNode($attr3, 'attribute_3');
                $tree[$attr2]['children'][$attr3]['_path'] = [
                    'attribute_1' => $category,
                    'attribute_2' => $attr2,
                    'attribute_3' => $attr3,
                ];
            }
            $this->addTo($tree[$attr2]['children'][$attr3], $qty, $weight, $row->order_id, $productId);

            // level 3 — product
            $products = &$tree[$attr2]['children'][$attr3]['children'];
            if (!isset($products[$productKey])) {
                $products[$productKey] = $this->blankNode((string) $row->product_name, 'product');
                $products[$productKey]['product_id'] = $productId ?: null;
            }
            $this->addTo($products[$productKey], $qty, $weight, $row->order_id, $productId);

            // level 4 — the orders themselves
            $orderKey = (string) $row->order_id;
            if (!isset($products[$productKey]['children'][$orderKey])) {
                $products[$productKey]['children'][$orderKey] = array_merge(
                    $this->blankNode((string) ($row->order_number ?: ('#' . $row->order_id)), 'order'),
                    [
                        'order_id' => (int) $row->order_id,
                        'order_status' => (string) $row->order_status,
                        'customer_name' => (string) $row->customer_name,
                        'order_date' => $row->order_date ? substr((string) $row->order_date, 0, 10) : null,
                        'is_dispatched' => !empty($row->eta_calculated_at),
                    ]
                );
            }
            $this->addTo($products[$productKey]['children'][$orderKey], $qty, $weight, $row->order_id, $productId);
            unset($products);
        }

        $stock = app(OvernightStockService::class);

        // ⭐⭐ Stock is PRODUCT STATE, not order flow: the same product can sit on
        //     ten orders and still be one physical pile. It is attached ONCE per
        //     node over that node's DISTINCT products — accumulating it per line
        //     would multiply it by the number of orders.
        //     Category nodes match on their FULL ancestor path, so they also count
        //     stocked products that happen to have no orders today (deliberate:
        //     the question at the supplier is "what do we already have").
        $finalize = function (array $node) use (&$finalize, $stock, $stockMap, $stockCatalog) {
            $node['quantity'] = round($node['quantity'], 2);
            $node['weight'] = round($node['weight'], 2);
            $node['order_count'] = count($node['_orders']);
            $node['product_count'] = count($node['_products']);

            if (!empty($stockMap)) {
                if ($node['level'] === 'product' && !empty($node['product_id'])) {
                    $storage = $stock->sumFor($stockMap, [$node['product_id']]);
                    if ($storage !== null) {
                        $node['storage'] = $storage;
                    }
                } elseif (!empty($node['_path']) && !empty($stockCatalog)) {
                    $storage = $stock->sumForCategory($stockCatalog, $node['_path']);
                    if ($storage !== null) {
                        $node['storage'] = $storage;
                    }
                }
            }

            $children = [];
            foreach ($node['children'] as $child) {
                $children[] = $finalize($child);
            }
            usort($children, fn ($a, $b) => $b['quantity'] <=> $a['quantity']);

            $node['children'] = $children;
            $node['has_children'] = count($children) > 0;

            unset($node['_orders'], $node['_products'], $node['_path']);

            return $node;
        };

        $out = [];
        foreach ($tree as $node) {
            $out[] = $finalize($node);
        }
        usort($out, fn ($a, $b) => $b['quantity'] <=> $a['quantity']);

        return [
            'key' => $key,
            'title' => $title,
            'summary' => [
                'orders' => count($orderIds),
                'quantity' => round($totalQty, 2),
                'weight' => round($totalWeight, 2),
            ],
            'tree' => $out,
        ];
    }

    private function blankNode(string $name, string $level): array
    {
        return [
            'name' => $name,
            'level' => $level,
            'quantity' => 0.0,
            'weight' => 0.0,
            'order_count' => 0,
            'product_count' => 0,
            'children' => [],
            '_orders' => [],
            '_products' => [],
        ];
    }

    private function addTo(array &$node, float $qty, float $weight, $orderId, int $productId): void
    {
        $node['quantity'] += $qty;
        $node['weight'] += $weight;
        $node['_orders'][$orderId] = true;
        if ($productId > 0) {
            $node['_products'][$productId] = true;
        }
    }

    // =================================================================
    // helpers
    // =================================================================

    /**
     * The level-1 category this screen shows. Data, not a constant, so the
     * owner can repoint or rename it without a deploy. Never throws.
     */
    private function category(): string
    {
        try {
            $value = DB::table('t_fin_config')->where('config_key', 'LACARNE_CATEGORY')->value('config_value');
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        } catch (\Throwable $e) {
            // no config table in this environment — fall through
        }

        return self::DEFAULT_CATEGORY;
    }

    /**
     * ⚠ A future date is clamped to today rather than rejected: phones in a
     *   different timezone (or a clock a few minutes fast) would otherwise be
     *   refused for no reason the user could understand.
     */
    private function safeDate($raw): string
    {
        try {
            $c = $raw ? Carbon::parse($raw) : Carbon::today();
        } catch (\Throwable $e) {
            $c = Carbon::today();
        }
        if ($c->gt(Carbon::today())) {
            $c = Carbon::today();
        }

        return $c->format('Y-m-d');
    }

    private function photosAvailable(): bool
    {
        try {
            return Schema::hasTable(self::PHOTO_TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Invoice photos for one date, newest first. Never throws. */
    private function photosFor(string $date): array
    {
        if (!$this->photosAvailable()) {
            return [];
        }

        try {
            // Per-photo delete right, decided HERE so the UIs never have to
            // guess: a rostered rider may only remove their own upload, so a ×
            // on someone else's photo would just be a button that 403s.
            $canEditDate = $this->canEditDate($date);
            $isRoster = $this->access()['via'] === LaCarneAccessService::VIA_ROSTER;
            $selfId = (int) auth()->id();

            return DB::table(self::PHOTO_TABLE . ' as ph')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'ph.uploaded_by')
                ->where('ph.photo_date', $date)
                ->orderByDesc('ph.id')
                ->get(['ph.id', 'ph.photo_path', 'ph.note', 'ph.created_at', 'ph.uploaded_by', 'u.fullname as by_name'])
                ->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'url' => $this->publicUrl($r->photo_path),
                    'note' => $r->note,
                    'by_name' => $r->by_name,
                    'created_at' => $r->created_at ? substr((string) $r->created_at, 0, 16) : null,
                    // must agree with canDeletePhoto() — same rule, precomputed
                    'can_delete' => $canEditDate && (!$isRoster || (int) ($r->uploaded_by ?? 0) === $selfId),
                ])->all();
        } catch (\Throwable $e) {
            Log::warning('La Carne photos unavailable', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * ⚠ Streamed with putFileAs, never file_get_contents: this accepts up to 8
     *   photos of up to 8 MB, and reading them all into strings would blow PHP's
     *   memory limit on one request.
     */
    private function storeOne($file, string $date): string
    {
        $now = now();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic'], true)) {
            $ext = 'jpg';
        }

        $dir = 'lacarne/invoices/' . $now->format('Y') . '/' . $now->format('m');
        $name = 'lacarne_' . str_replace('-', '', $date) . '_' . $now->format('Ymd_His') . '_' . uniqid() . '.' . $ext;

        Storage::disk('public')->putFileAs($dir, $file, $name);

        return $dir . '/' . $name;
    }

    /**
     * ⭐⭐ Images are served by GET /public-storage/{path}, a route that sits
     *     OUTSIDE every auth group. That is what lets the phone's <Image> render
     *     them: the app sends a Sanctum bearer token and no session cookie, so an
     *     auth-gated image URL would come back blank. Absolute so one payload
     *     serves both web and mobile.
     */
    private function publicUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $rel = '/public-storage/' . ltrim($path, '/');
        try {
            $base = request() ? request()->getSchemeAndHttpHost() : null;
        } catch (\Throwable $e) {
            $base = null;
        }

        return $base ? rtrim($base, '/') . $rel : $rel;
    }
}
