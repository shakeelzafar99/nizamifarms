<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use App\Models\FIN\BusinessUnitModel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoices — Analysis  (Jul-2026)
 *
 * A READ-ONLY, analysis-oriented explorer over delivered invoices — deliberately
 * separate from the operational Orders page (resources/views/pages/orders, which is
 * for running the business, not exploring it). Built for owner/analyst logins.
 *
 * Reconciliation (matches the HQ Executive dashboard and Monthly Reports):
 *   - Revenue is attributed to the DELIVERED date (MIN(changed_at) where
 *     status_code='delivered'), NOT order_date — same basis as HQ/Reports.
 *   - NF vs Khaas is split at LINE-ITEM level by t_crm_prod_product.business_unit_id
 *     (Khaas share = sum of khaas-BU line_totals; NF = total_price − khaas share).
 *   - Qurbani is the seasonal filter (QurbaniFinanceFilter), so a qurbani order's
 *     whole amount is qurbani, never split into NF/Khaas.
 *   - Shopify orders are excluded (external_source != 'shopify'), matching HQ/Reports.
 *   - Payment channel uses OrderModel::paymentChannel() (cash vs online).
 *   - "Invoice sent" reads the WhatsApp send log (t_wa_messages.related_order_number),
 *     the same signal as the Orders-page invoice tick.
 *
 * All endpoints are GET. There is nothing to write here.
 */
class InvoiceAnalysisController extends Controller
{
    private const PER_PAGE = 25;

    /** Shell page. Data is loaded by the view via /invoices/data. */
    public function index()
    {
        return view('pages.invoices.index');
    }

    /** Filtered + paginated invoice rows plus a period summary (JSON). */
    public function data(Request $request)
    {
        [$from, $to] = $this->range($request);
        $brand = $this->brand($request);

        $qClause = $this->qurbaniExpr();
        $eff     = $this->effAmountExpr($brand);

        $base = $this->baseQuery($from, $to, $request);

        // --- Summary over the WHOLE filtered set (not just the page) ---------------------------
        $agg = (clone $base)->selectRaw("
            COUNT(*) as cnt,
            COALESCE(SUM(o.total_price), 0) as total_all,
            COALESCE(SUM(CASE WHEN {$qClause} THEN o.total_price ELSE 0 END), 0) as total_qb,
            COALESCE(SUM(CASE WHEN NOT ({$qClause}) THEN COALESCE(k.khaas_total, 0) ELSE 0 END), 0) as total_kh,
            COALESCE(SUM(CASE WHEN NOT ({$qClause}) THEN (o.total_price - COALESCE(k.khaas_total, 0)) ELSE 0 END), 0) as total_nf,
            COALESCE(SUM(CASE WHEN {$this->cashSql('o')} THEN 0 ELSE 1 END), 0) as online_cnt,
            COALESCE(SUM(CASE WHEN wa.sent IS NOT NULL THEN 1 ELSE 0 END), 0) as sent_cnt
        ")->first();

        $count   = (int) $agg->cnt;
        $revenue = $brand === 'kh' ? (float) $agg->total_kh
                 : ($brand === 'nf' ? (float) $agg->total_nf
                 : ($brand === 'qb' ? (float) $agg->total_qb : (float) $agg->total_all));

        // --- Page of rows ----------------------------------------------------------------------
        $page = max(1, (int) $request->get('page', 1));
        $order = $this->orderByExpr($request, $eff);

        $rows = (clone $base)
            ->selectRaw("
                o.id,
                o.order_number,
                o.total_price,
                o.payment_method,
                o.external_source,
                h.delivered_at,
                DATE(h.delivered_at) as delivery_date,
                c.customer_type,
                c.city,
                COALESCE(NULLIF(TRIM(o.name), ''), TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')))) as customer_name,
                COALESCE(k.khaas_total, 0) as khaas_total,
                ({$qClause}) as is_qurbani,
                (wa.sent IS NOT NULL) as invoice_sent,
                ({$eff}) as eff_amount
            ")
            ->orderByRaw($order)
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get()
            ->map(fn ($r) => $this->shapeRow($r))
            ->all();

        return response()->json([
            'rows'    => $rows,
            'summary' => [
                'count'      => $count,
                'revenue'    => round($revenue, 2),
                'average'    => $count ? round($revenue / $count, 2) : 0,
                'online_cnt' => (int) $agg->online_cnt,
                'sent_cnt'   => (int) $agg->sent_cnt,
            ],
            'page'     => $page,
            'per_page' => self::PER_PAGE,
            'total'    => $count,
            'pages'    => (int) ceil($count / self::PER_PAGE),
        ]);
    }

    /** One invoice's full detail for the drawer (JSON). */
    public function detail($id)
    {
        $o = DB::table('t_crm_prod_order as o')
            ->leftJoin('t_crm_prod_customer as c', 'o.customer_id', '=', 'c.id')
            ->leftJoin('t_sys_user as r', 'o.assigned_rider_user_id', '=', 'r.id')
            ->where('o.id', $id)
            ->selectRaw("
                o.id, o.order_number, o.order_status, o.total_price, o.subtotal_price,
                o.discount_total, o.shipping_total, o.tip_amount, o.payment_method,
                o.external_source, o.name,
                c.customer_type, c.city, c.province, c.phone,
                COALESCE(NULLIF(TRIM(o.name), ''), TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')))) as customer_name,
                r.fullname as rider_name
            ")
            ->first();

        if (!$o) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $khaasBu = $this->khaasBuId();

        $items = DB::table('t_crm_prod_order_line_item as li')
            ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
            ->where('li.order_id', $id)
            ->selectRaw('li.name, li.sku, li.quantity, li.unit_price, li.line_total, p.business_unit_id')
            ->get()
            ->map(fn ($it) => [
                'name'       => $it->name,
                'sku'        => $it->sku,
                'quantity'   => (float) $it->quantity,
                'unit_price' => (float) $it->unit_price,
                'line_total' => (float) $it->line_total,
                'brand'      => ((int) $it->business_unit_id === $khaasBu) ? 'kh' : 'nf',
            ])->all();

        $history = DB::table('t_crm_order_status_history')
            ->where('order_id', $id)
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get(['status_code', 'changed_at'])
            ->map(fn ($h) => [
                'status'     => $h->status_code,
                'changed_at' => $h->changed_at,
            ])->all();

        $sent = DB::table('t_wa_messages')
            ->where('related_order_number', $o->order_number)
            ->where('direction', 'outbound')
            ->where('content', 'like', 'Invoice #%')
            ->where('status', '<>', 'failed')
            ->max('created_at');

        return response()->json([
            'order' => [
                'order_number'   => $o->order_number,
                'order_status'   => $o->order_status,
                'external_source'=> $o->external_source,
                'total_price'    => (float) $o->total_price,
                'subtotal_price' => (float) $o->subtotal_price,
                'discount_total' => (float) $o->discount_total,
                'shipping_total' => (float) $o->shipping_total,
                'tip_amount'     => (float) $o->tip_amount,
                'channel'        => OrderModel::paymentChannel($o->payment_method),
                'rider_name'     => $o->rider_name,
            ],
            'customer' => [
                'name'     => $o->customer_name,
                'type'     => $o->customer_type ?: 'regular',
                'city'     => $o->city,
                'province' => $o->province,
                'phone'    => $o->phone,
            ],
            'items'        => $items,
            'history'      => $history,
            'invoice_sent' => $sent,
        ]);
    }

    /** CSV of the current filter selection (streamed). */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $qClause = $this->qurbaniExpr();

        $rows = $this->baseQuery($from, $to, $request)
            ->selectRaw("
                o.order_number,
                o.total_price,
                o.payment_method,
                DATE(h.delivered_at) as delivery_date,
                c.customer_type,
                c.city,
                COALESCE(NULLIF(TRIM(o.name), ''), TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')))) as customer_name,
                COALESCE(k.khaas_total, 0) as khaas_total,
                ({$qClause}) as is_qurbani,
                (wa.sent IS NOT NULL) as invoice_sent
            ")
            ->orderByRaw('h.delivered_at DESC')
            ->cursor();

        $filename = 'invoices-' . $from . '-to-' . $to . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['order_number', 'delivery_date', 'customer', 'type', 'city', 'amount', 'brand_nf', 'brand_khaas', 'brand_qurbani', 'payment', 'invoice_sent']);
            foreach ($rows as $r) {
                $shaped = $this->shapeRow($r);
                fputcsv($out, [
                    $shaped['order_number'],
                    $shaped['delivery_date'],
                    $shaped['customer_name'],
                    $shaped['customer_type'],
                    $shaped['city'],
                    $shaped['total_price'],
                    $shaped['nf'],
                    $shaped['kh'],
                    $shaped['qb'],
                    $shaped['channel'],
                    $shaped['invoice_sent'] ? 'yes' : 'no',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ------------------------------------------------------------------------------------------
    // Query building
    // ------------------------------------------------------------------------------------------

    /**
     * Base delivered-invoice query with the shared joins + all active filters applied,
     * WITHOUT select / order / limit. Callers clone it for aggregate, page, and export.
     */
    private function baseQuery(string $from, string $to, Request $request)
    {
        $khaasBu = $this->khaasBuId();
        $qClause = $this->qurbaniExpr();

        $delivered = DB::table('t_crm_order_status_history')
            ->select('order_id', DB::raw('MIN(changed_at) as delivered_at'))
            ->where('status_code', 'delivered')
            ->groupBy('order_id');

        $khaas = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
            ->select('li.order_id', DB::raw('SUM(li.line_total) as khaas_total'))
            ->where('p.business_unit_id', $khaasBu)
            ->groupBy('li.order_id');

        // Orders carrying a Qurbani-attributed product. Pre-joined (one row per order)
        // instead of a per-row EXISTS so the all-time aggregate stays fast.
        $qurbaniProd = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
            ->select('li.order_id', DB::raw('1 as qp'))
            ->whereRaw("LOWER(COALESCE(p.attribute_1, '')) = 'qurbani'")
            ->groupBy('li.order_id');

        $wa = DB::table('t_wa_messages')
            ->select('related_order_number', DB::raw('MAX(1) as sent'))
            ->where('direction', 'outbound')
            ->where('content', 'like', 'Invoice #%')
            ->where('status', '<>', 'failed')
            ->groupBy('related_order_number');

        $q = DB::table('t_crm_prod_order as o')
            ->joinSub($delivered, 'h', 'o.id', '=', 'h.order_id')
            ->leftJoin('t_crm_prod_customer as c', 'o.customer_id', '=', 'c.id')
            ->leftJoinSub($khaas, 'k', 'k.order_id', '=', 'o.id')
            ->leftJoinSub($qurbaniProd, 'qp', 'qp.order_id', '=', 'o.id')
            ->leftJoinSub($wa, 'wa', 'wa.related_order_number', '=', 'o.order_number')
            ->whereBetween('h.delivered_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            // Match HQ/Reports: Shopify orders are excluded from the revenue view.
            ->where(function ($w) {
                $w->whereNull('o.external_source')->orWhere('o.external_source', '!=', 'shopify');
            });

        // Brand
        $brand = $this->brand($request);
        if ($brand === 'qb') {
            $q->whereRaw($qClause);
        } elseif ($brand === 'kh') {
            $q->whereRaw("NOT ({$qClause})")->where('k.khaas_total', '>', 0);
        } elseif ($brand === 'nf') {
            $q->whereRaw("NOT ({$qClause})")->whereRaw('(o.total_price - COALESCE(k.khaas_total, 0)) > 0');
        }

        // Customer type
        $type = $request->get('type');
        if (in_array($type, ['regular', 'shop'], true)) {
            $q->where('c.customer_type', $type);
        }

        // Payment channel
        $pay = $request->get('pay');
        if ($pay === 'cash') {
            $q->whereRaw($this->cashSql('o'));
        } elseif ($pay === 'online') {
            $q->whereRaw('NOT (' . $this->cashSql('o') . ')');
        }

        // Search — order number or customer name
        $search = trim((string) $request->get('q', ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $q->where(function ($w) use ($like) {
                $w->where('o.order_number', 'like', $like)
                  ->orWhere('o.name', 'like', $like)
                  ->orWhereRaw("CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) LIKE ?", [$like]);
            });
        }

        return $q;
    }

    /** Turn a raw DB row into the shape the front-end + CSV consume. */
    private function shapeRow($r): array
    {
        $total = (float) $r->total_price;
        $khaas = (float) ($r->khaas_total ?? 0);
        $isQb  = (int) $r->is_qurbani === 1;

        if ($isQb) {
            $nf = 0.0; $kh = 0.0; $qb = $total;
        } else {
            $kh = min($khaas, $total);
            $nf = max(0.0, $total - $kh);
            $qb = 0.0;
        }

        return [
            'id'            => (int) $r->id,
            'order_number'  => $r->order_number,
            'delivery_date' => $r->delivery_date,
            'customer_name' => $r->customer_name ?: 'Unknown',
            'customer_type' => $r->customer_type ?: 'regular',
            'city'          => $r->city,
            'total_price'   => round($total, 2),
            'nf'            => round($nf, 2),
            'kh'            => round($kh, 2),
            'qb'            => round($qb, 2),
            'channel'       => OrderModel::paymentChannel($r->payment_method),
            'invoice_sent'  => (bool) $r->invoice_sent,
            'source'        => $r->external_source ?: 'webapp',
        ];
    }

    /** SQL boolean: TRUE when the order's payment channel is cash (mirrors OrderModel::paymentChannel). */
    private function cashSql(string $alias): string
    {
        return "(LOWER(TRIM(COALESCE({$alias}.payment_method, ''))) = '' "
             . "OR LOWER({$alias}.payment_method) LIKE '%cash%' "
             . "OR LOWER({$alias}.payment_method) LIKE '%cod%')";
    }

    /** SQL expression for the amount shown, given the active brand filter. */
    private function effAmountExpr(string $brand): string
    {
        return match ($brand) {
            'kh' => 'COALESCE(k.khaas_total, 0)',
            'nf' => '(o.total_price - COALESCE(k.khaas_total, 0))',
            default => 'o.total_price', // all + qb
        };
    }

    /** ORDER BY fragment from the sort request. */
    private function orderByExpr(Request $request, string $eff): string
    {
        $dir = strtolower($request->get('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';
        return match ($request->get('sort', 'date')) {
            'customer' => "customer_name {$dir}",
            'amount'   => "{$eff} {$dir}",
            default    => "h.delivered_at {$dir}, o.id {$dir}",
        };
    }

    /** Normalized brand filter value. */
    private function brand(Request $request): string
    {
        $b = $request->get('brand', 'all');
        return in_array($b, ['nf', 'kh', 'qb'], true) ? $b : 'all';
    }

    /**
     * Resolve [from, to] Y-m-d. Accepts explicit from/to, else a preset
     * (month | lastmonth | 30 | all), defaulting to the current month.
     */
    private function range(Request $request): array
    {
        $from = $request->get('from');
        $to   = $request->get('to');
        if ($this->isDate($from) && $this->isDate($to)) {
            return $from <= $to ? [$from, $to] : [$to, $from];
        }

        $today = Carbon::today();
        return match ($request->get('preset', 'month')) {
            'lastmonth' => [$today->copy()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'),
                            $today->copy()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d')],
            '30'        => [$today->copy()->subDays(29)->format('Y-m-d'), $today->format('Y-m-d')],
            'all'       => ['2020-01-01', $today->format('Y-m-d')],
            default     => [$today->copy()->startOfMonth()->format('Y-m-d'), $today->format('Y-m-d')],
        };
    }

    private function isDate($v): bool
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
    }

    private function khaasBuId(): int
    {
        static $id = null;
        if ($id === null) {
            $id = (int) (BusinessUnitModel::where('code', 'KHAAS')->value('id') ?? 0);
        }
        return $id;
    }

    /**
     * SQL boolean identifying a Qurbani order. Logically identical to
     * ReportsController::qurbaniOrderSqlClause (so this page reconciles with the
     * Qurbani Expenses tab / Monthly Reports / HQ) but expressed against the
     * pre-joined `qp` subquery instead of a per-row EXISTS, for speed. Requires
     * baseQuery()'s `qp` join to be present.
     */
    private function qurbaniExpr(): string
    {
        return '(o.qurbani_day IS NOT NULL OR o.qurbani_slot IS NOT NULL '
             . 'OR o.qurbani_region IS NOT NULL OR o.qurbani_delivery_type IS NOT NULL '
             . 'OR qp.qp IS NOT NULL)';
    }
}
