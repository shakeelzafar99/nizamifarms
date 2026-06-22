<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\FIN\ConfigModel;
use App\Services\QurbaniBundleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class QurbaniWebController extends Controller
{
    /**
     * /qurbani/invoices  (formerly /qurbani/orders).
     *
     * The invoice-level Qurbani page — one row per order, with payment /
     * stamp / WhatsApp / Invoice action buttons. Renamed in May-2026 to
     * make room for the new region-wise item-level Qurbani Orders page
     * (see orders() below) which mirrors the mobile dispatch layout and
     * drives the Box Label printer.
     */
    public function invoices()
    {
        $fieldOptions = DB::table('t_crm_qurbani_field_options')
            ->where('is_active', 1)
            ->orderBy('field_name')
            ->orderBy('display_order')
            ->get()
            ->groupBy('field_name');

        $regions = ($fieldOptions['qurbani_region'] ?? collect())->pluck('option_value');
        $subRegions = ($fieldOptions['qurbani_sub_region'] ?? collect())->pluck('option_value');
        $days = ($fieldOptions['qurbani_day'] ?? collect())->pluck('option_value');
        $slots = ($fieldOptions['qurbani_slot'] ?? collect())->pluck('option_value');
        $deliveryTypes = ($fieldOptions['qurbani_delivery_type'] ?? collect())->pluck('option_value');
        // New Apr-2026 attributes (Qurbani Type + Paya). Pluck the option
        // values so the orders page can render dropdowns / filters — if a
        // site hasn't run the migration yet these collections are simply
        // empty and the UI degrades gracefully.
        $qurbaniTypes = ($fieldOptions['qurbani_type'] ?? collect())->pluck('option_value');
        $payaOptions = ($fieldOptions['qurbani_paya'] ?? collect())->pluck('option_value');

        // Category Level 2 values that appear on qurbani products (e.g. Goat (Bakra), Beef).
        // Used to populate the Category filter dropdown on the qurbani orders page so the
        // selectable list matches the products actually tagged as qurbani.
        $categories = DB::table('t_crm_prod_product')
            ->whereRaw("LOWER(attribute_1) = 'qurbani'")
            ->whereNotNull('attribute_2')
            ->where('attribute_2', '!=', '')
            ->distinct()
            ->orderBy('attribute_2')
            ->pluck('attribute_2');

        $riders = DB::table('t_sys_user as u')
            ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'u.id')
            ->where(function ($q) {
                $q->whereNull('p.user_id')->orWhere('p.active', 1);
            })
            ->where('u.is_active', 1)
            ->orderBy('u.fullname')
            ->select('u.id', 'u.fullname')
            ->get();

        $isTaimur = DB::table('t_sys_user_role as ur')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', auth()->id())
            ->whereRaw('LOWER(r.urole_name) = ?', ['taimur'])
            ->exists();
        $deleteEnabled = ConfigModel::get('qurbani_delete_enabled', '0') === '1';

        // Receiving bank list (HBL / MBL / EP / JC …) for the Add Payment
        // modal chip row. Reuses the same lookup that powers Online Approvals
        // so the two flows stay in sync if the finance team edits the list.
        $receivingAccounts = \App\Models\FIN\OnlineReceivingAccountModel::active()
            ->ordered()
            ->get(['id', 'name', 'short_code', 'color_hex']);

        // Default payment method (cash/online) from Qurbani settings — used
        // to pre-select the payment-method chip when an order is still
        // "unpaid" (no stored payment_method yet) so the team can just hit
        // Record without reselecting every time. Falls back to 'cash' so the
        // historical default is preserved when the setting is missing.
        $defaultPaymentMethod = \App\Models\FIN\ConfigModel::get('qurbani_default_payment_method', 'cash');
        if (!in_array($defaultPaymentMethod, ['cash', 'online'], true)) {
            $defaultPaymentMethod = 'cash';
        }

        return view('pages.qurbani.invoices', compact(
            'regions', 'subRegions', 'days', 'slots', 'deliveryTypes',
            'qurbaniTypes', 'payaOptions',
            'categories', 'riders', 'fieldOptions', 'isTaimur',
            'deleteEnabled', 'receivingAccounts', 'defaultPaymentMethod'
        ));
    }

    /**
     * JSON feed for the invoices page (formerly getOrders).
     */
    public function getInvoices(Request $request)
    {
        $query = DB::table('t_crm_prod_order as o')
            ->leftJoin('t_crm_prod_customer as c', 'o.customer_id', '=', 'c.id')
            ->leftJoin('t_sys_user as r', 'o.assigned_rider_user_id', '=', 'r.id')
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNotNull('o.qurbani_day')
                          ->orWhereNotNull('o.qurbani_slot')
                          ->orWhereNotNull('o.qurbani_region')
                          ->orWhereNotNull('o.qurbani_delivery_type');
                })
                ->orWhereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_prod_order_line_item as li')
                        ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                        ->whereColumn('li.order_id', 'o.id')
                        ->whereRaw("LOWER(p.attribute_1) = 'qurbani'");
                });
            });

        // Helper: apply a qurbani attribute filter.
        // '__unassigned__' matches NULL or empty string (line items without the value set).
        // Uses OR: order-level match OR any line item match (same pattern as regular values).
        $applyQurbaniFilter = function ($query, $orderCol, $liCol, $value) {
            if ($value === '__unassigned__') {
                $query->where(function ($q) use ($orderCol, $liCol) {
                    $q->where(function ($iq) use ($orderCol) {
                        $iq->whereNull($orderCol)->orWhere($orderCol, '');
                    })->orWhereExists(function ($sub) use ($liCol) {
                        $sub->select(DB::raw(1))
                            ->from('t_crm_prod_order_line_item as fli')
                            ->whereColumn('fli.order_id', 'o.id')
                            ->where(function ($sq) use ($liCol) {
                                $sq->whereNull($liCol)->orWhere($liCol, '');
                            });
                    });
                });
            } else {
                $query->where(function ($q) use ($orderCol, $liCol, $value) {
                    $q->where($orderCol, $value)
                      ->orWhereExists(function ($sub) use ($liCol, $value) {
                          $sub->select(DB::raw(1))
                              ->from('t_crm_prod_order_line_item as fli')
                              ->whereColumn('fli.order_id', 'o.id')
                              ->where($liCol, $value);
                      });
                });
            }
        };

        if ($request->filled('region')) {
            $applyQurbaniFilter($query, 'o.qurbani_region', 'fli.qurbani_region', $request->region);
        }
        if ($request->filled('day')) {
            $applyQurbaniFilter($query, 'o.qurbani_day', 'fli.qurbani_day', $request->day);
        }
        if ($request->filled('slot')) {
            $applyQurbaniFilter($query, 'o.qurbani_slot', 'fli.qurbani_slot', $request->slot);
        }
        if ($request->filled('delivery_type')) {
            $applyQurbaniFilter($query, 'o.qurbani_delivery_type', 'fli.qurbani_delivery_type', $request->delivery_type);
        }
        if ($request->filled('sub_region')) {
            $applyQurbaniFilter($query, 'o.qurbani_sub_region', 'fli.qurbani_sub_region', $request->sub_region);
        }
        // qurbani_type + qurbani_paya: line-item only columns
        if ($request->filled('qurbani_type')) {
            $qtype = $request->qurbani_type;
            if ($qtype === '__unassigned__') {
                $query->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_prod_order_line_item as fli')
                        ->whereColumn('fli.order_id', 'o.id')
                        ->where(function ($sq) {
                            $sq->whereNull('fli.qurbani_type')->orWhere('fli.qurbani_type', '');
                        });
                });
            } else {
                $query->whereExists(function ($sub) use ($qtype) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_prod_order_line_item as fli')
                        ->whereColumn('fli.order_id', 'o.id')
                        ->where('fli.qurbani_type', $qtype);
                });
            }
        }
        if ($request->filled('qurbani_paya')) {
            $paya = $request->qurbani_paya;
            if ($paya === '__unassigned__') {
                $query->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_prod_order_line_item as fli')
                        ->whereColumn('fli.order_id', 'o.id')
                        ->where(function ($sq) {
                            $sq->whereNull('fli.qurbani_paya')->orWhere('fli.qurbani_paya', '');
                        });
                });
            } else {
                $query->whereExists(function ($sub) use ($paya) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_prod_order_line_item as fli')
                        ->whereColumn('fli.order_id', 'o.id')
                        ->where('fli.qurbani_paya', $paya);
                });
            }
        }
        if ($request->filled('status')) {
            $query->where('o.order_status', $request->status);
        } else {
            // By default, exclude cancelled orders from the main listing
            $query->where(function ($q) {
                $q->whereNull('o.order_status')
                  ->orWhereRaw("LOWER(o.order_status) <> 'cancelled'");
            });
        }
        // Payment status filter: paid | partial | unpaid. Preferred over legacy `status`.
        if ($request->filled('payment_status')) {
            $query->where('o.payment_status', $request->payment_status);
        }
        // Category (category_level_2) filter: matches orders that have at least one line item
        // whose product's attribute_2 equals the requested value. We only narrow the order set
        // here; per-line filtering still happens below when the category is also passed as an
        // item filter so counts/items shown in the row are consistent.
        if ($request->filled('category')) {
            $cat = $request->category;
            if ($cat === '__unassigned__') {
                $query->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_prod_order_line_item as cli')
                        ->join('t_crm_prod_product as cp', 'cli.product_id', '=', 'cp.id')
                        ->whereColumn('cli.order_id', 'o.id')
                        ->where(function ($sq) {
                            $sq->whereNull('cp.attribute_2')->orWhere('cp.attribute_2', '');
                        });
                });
            } else {
                $query->whereExists(function ($sub) use ($cat) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_prod_order_line_item as cli')
                        ->join('t_crm_prod_product as cp', 'cli.product_id', '=', 'cp.id')
                        ->whereColumn('cli.order_id', 'o.id')
                        ->where('cp.attribute_2', $cat);
                });
            }
        }
        if ($request->filled('customer')) {
            $customerSearch = $request->customer;
            $query->where(function($q) use ($customerSearch) {
                $q->whereRaw("CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) LIKE ?", ["%{$customerSearch}%"])
                  ->orWhere('c.phone', 'LIKE', "%{$customerSearch}%")
                  ->orWhere('o.order_number', 'LIKE', "%{$customerSearch}%");
            });
        }

        $orders = $query->select(
                'o.id', 'o.order_number', 'o.order_status', 'o.order_date',
                'o.total_price', 'o.payment_method', 'o.payment_status', 'o.total_paid',
                DB::raw("(o.total_price - COALESCE(o.total_paid, 0)) as balance_remaining"),
                'o.qurbani_day', 'o.qurbani_slot', 'o.qurbani_region', 'o.qurbani_sub_region', 'o.qurbani_delivery_type',
                'o.note', 'o.assigned_rider_user_id',
                DB::raw("CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) as customer_name"),
                'c.phone as customer_phone',
                DB::raw("COALESCE(r.fullname,'') as rider_name")
            )
            ->orderByDesc('o.order_date')
            // May-2026: raised from 500 → 1200. The previous cap was being
            // hit in production (Qurbani 2026 crossed 500 orders), which
            // silently truncated the orders feeding the per-category
            // filter chips above the table — so e.g. "Cow Share (Hissa)"
            // showed 573 in the chip but 583 in the booked-summary cards
            // (which run the same data via getOrderStats with no limit).
            // Bumping the cap to 1200 covers projected Qurbani 2027 volume
            // with headroom; if we ever hit this again we should switch
            // the chips to read totals from getOrderStats instead.
            ->limit(1200)
            ->get();

        // Attach line items (product names + qty + qurbani fields) for each order
        $orderIds = $orders->pluck('id')->toArray();
        $lineItems = [];
        if (!empty($orderIds)) {
            $lineItems = DB::table('t_crm_prod_order_line_item as li')
                ->leftJoin('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                ->whereIn('li.order_id', $orderIds)
                ->select('li.order_id', 'li.name', 'li.quantity', 'li.line_total', 'li.qurbani_day', 'li.qurbani_slot', 'li.qurbani_region', 'li.qurbani_sub_region', 'li.qurbani_delivery_type', 'li.qurbani_type', 'li.qurbani_paya', 'li.instructions', 'p.attribute_2 as category_level_2')
                ->get()
                ->groupBy('order_id');
        }

        // Build active line-item-level filters
        $itemFilters = [];
        if ($request->filled('day')) $itemFilters['qurbani_day'] = $request->day;
        if ($request->filled('slot')) $itemFilters['qurbani_slot'] = $request->slot;
        if ($request->filled('region')) $itemFilters['qurbani_region'] = $request->region;
        if ($request->filled('sub_region')) $itemFilters['qurbani_sub_region'] = $request->sub_region;
        if ($request->filled('delivery_type')) $itemFilters['qurbani_delivery_type'] = $request->delivery_type;
        if ($request->filled('qurbani_type')) $itemFilters['qurbani_type'] = $request->qurbani_type;
        if ($request->filled('qurbani_paya')) $itemFilters['qurbani_paya'] = $request->qurbani_paya;
        if ($request->filled('category')) $itemFilters['category_level_2'] = $request->category;
        $hasItemFilters = !empty($itemFilters);

        foreach ($orders as &$order) {
            $allItems = $lineItems[$order->id] ?? collect();
            $order->all_items_count = $allItems->count();
            $order->all_items_qty = $allItems->sum('quantity');

            if ($hasItemFilters) {
                $filtered = $allItems->filter(function ($item) use ($itemFilters) {
                    foreach ($itemFilters as $key => $val) {
                        $itemVal = $item->$key ?? null;
                        if ($val === '__unassigned__') {
                            if (!empty($itemVal)) return false;
                        } else {
                            if ($itemVal !== $val) return false;
                        }
                    }
                    return true;
                });
                $order->line_items = $filtered->values();
                $order->product_names = $filtered->pluck('name')->implode(', ');
                $order->total_qty = $filtered->sum('quantity');
                $order->filtered = true;
            } else {
                $order->line_items = $allItems->values();
                $order->product_names = $allItems->pluck('name')->implode(', ');
                $order->total_qty = $allItems->sum('quantity');
                $order->filtered = false;
            }
        }

        return response()->json(['success' => true, 'orders' => $orders, 'has_item_filters' => $hasItemFilters]);
    }

    /**
     * Line-item-level aggregation for the Qurbani Orders "stats panel".
     * Ignores the filter row (always the big picture). Excludes cancelled orders.
     * Returns:
     *   days[]                   dynamic list of day labels (sorted, "Unassigned" last)
     *   categories[]             dynamic list of category_level_2 (typically Goat, Hissa)
     *   summary[delivery_type][day][category] = qty
     *   detail[delivery_type][day][category] = { slots[], regions[], cell[slot][region] = qty }
     */
    public function getOrderStats(Request $request)
    {
        $rows = DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_order_line_item as li', 'li.order_id', '=', 'o.id')
            ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
            // Qualify line items as Qurbani: either tagged on product attribute_1
            // or line item has any qurbani_* field set (covers legacy rows).
            ->where(function ($q) {
                $q->whereRaw("LOWER(COALESCE(p.attribute_1, '')) = 'qurbani'")
                  ->orWhereNotNull('li.qurbani_day')
                  ->orWhereNotNull('li.qurbani_slot')
                  ->orWhereNotNull('li.qurbani_region')
                  ->orWhereNotNull('li.qurbani_delivery_type');
            })
            ->where(function ($q) {
                $q->whereNull('o.order_status')
                  ->orWhereRaw("LOWER(o.order_status) <> 'cancelled'");
            })
            // Apr-2026: the bucket labels (delivery_type, day, slot, region)
            // are now sourced STRICTLY from the line item. The previous
            // `COALESCE(li.*, o.*, 'Unassigned')` fallback was copying the
            // order-level denormalised copy (which `OrderController::store`
            // / `update` populates from the FIRST qurbani line item) down
            // onto sibling lines that had their own attributes left blank,
            // producing phantom buckets — e.g. a Charity Rajanpur hissa
            // with only `Day 1` set was being counted under the sibling
            // Goat line's Delivery / Afternoon / Rawalpindi cell.
            // Lines with no line-item value now correctly surface under
            // 'Unassigned' so the team can spot and fix them at source.
            // Category stays per-item (product's attribute_2).
            ->select(
                DB::raw("COALESCE(NULLIF(li.qurbani_delivery_type, ''), 'Unassigned') as delivery_type"),
                DB::raw("COALESCE(NULLIF(li.qurbani_day, ''), 'Unassigned') as day"),
                DB::raw("COALESCE(NULLIF(p.attribute_2, ''), 'Uncategorized') as category"),
                DB::raw("COALESCE(NULLIF(li.qurbani_slot, ''), 'Unassigned') as slot"),
                DB::raw("COALESCE(NULLIF(li.qurbani_region, ''), 'Unassigned') as region"),
                DB::raw('COALESCE(SUM(li.quantity), 0) as qty')
            )
            ->groupBy('delivery_type', 'day', 'category', 'slot', 'region')
            ->get();

        // Field option ordering (so "Day 1 / Day 2 / Day 3" stays ordered even when the
        // raw result order is random).
        $dayOrder = DB::table('t_crm_qurbani_field_options')
            ->where('field_name', 'qurbani_day')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->pluck('option_value')
            ->toArray();
        $slotOrder = DB::table('t_crm_qurbani_field_options')
            ->where('field_name', 'qurbani_slot')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->pluck('option_value')
            ->toArray();
        $regionOrder = DB::table('t_crm_qurbani_field_options')
            ->where('field_name', 'qurbani_region')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->pluck('option_value')
            ->toArray();

        $sortByKnown = function (array $values, array $known): array {
            $values = array_values(array_unique($values));
            usort($values, function ($a, $b) use ($known) {
                $ia = array_search($a, $known, true);
                $ib = array_search($b, $known, true);
                // Known options first (in display order), then Unassigned last, others alphabetically.
                if ($a === 'Unassigned') return 1;
                if ($b === 'Unassigned') return -1;
                if ($ia === false && $ib === false) return strcmp((string) $a, (string) $b);
                if ($ia === false) return 1;
                if ($ib === false) return -1;
                return $ia <=> $ib;
            });
            return $values;
        };

        $daysSet = [];
        $catsSet = [];
        $summary = [];   // [delivery_type][day][category] = qty
        $detail = [];    // [delivery_type][day][category]['cell'][slot][region] = qty
        $slotsPerCell = [];   // [delivery_type][day][category] => [slots]
        $regionsPerCell = []; // [delivery_type][day][category] => [regions]

        foreach ($rows as $r) {
            $dt = (string) $r->delivery_type;
            $day = (string) $r->day;
            $cat = (string) $r->category;
            $slot = (string) $r->slot;
            $region = (string) $r->region;
            $qty = (int) $r->qty;

            $daysSet[$day] = true;
            $catsSet[$cat] = true;

            $summary[$dt][$day][$cat] = ($summary[$dt][$day][$cat] ?? 0) + $qty;
            $detail[$dt][$day][$cat]['cell'][$slot][$region] = ($detail[$dt][$day][$cat]['cell'][$slot][$region] ?? 0) + $qty;
            $slotsPerCell[$dt][$day][$cat][$slot] = true;
            $regionsPerCell[$dt][$day][$cat][$region] = true;
        }

        // Attach sorted slots / regions for each (delivery_type, day, category) cell.
        foreach ($detail as $dt => $days) {
            foreach ($days as $day => $cats) {
                foreach ($cats as $cat => $_) {
                    $detail[$dt][$day][$cat]['slots']   = $sortByKnown(array_keys($slotsPerCell[$dt][$day][$cat] ?? []), $slotOrder);
                    $detail[$dt][$day][$cat]['regions'] = $sortByKnown(array_keys($regionsPerCell[$dt][$day][$cat] ?? []), $regionOrder);
                }
            }
        }

        $days = $sortByKnown(array_keys($daysSet), $dayOrder);
        $categories = array_values(array_unique(array_keys($catsSet)));
        sort($categories);

        $hiddenCatsJson = ConfigModel::get('qurbani_hidden_stats_categories', '[]');
        $hiddenCats = json_decode($hiddenCatsJson, true) ?: [];
        if (!empty($hiddenCats)) {
            $categories = array_values(array_filter($categories, fn($c) => !in_array($c, $hiddenCats)));
            foreach ($summary as $dt => $dayMap) {
                foreach ($dayMap as $day => $catMap) {
                    foreach ($hiddenCats as $hc) { unset($summary[$dt][$day][$hc]); }
                }
            }
            foreach ($detail as $dt => $dayMap) {
                foreach ($dayMap as $day => $catMap) {
                    foreach ($hiddenCats as $hc) { unset($detail[$dt][$day][$hc]); }
                }
            }
        }

        // Apr-2026: also expose the global slot list (sorted by qurbani_slot
        // display_order, with 'Unassigned' pinned last) so the redesigned
        // summary card can render nested slot sub-rows under each day in a
        // stable, predictable order across delivery-type cards. We collect
        // every slot that actually appears in the rolled-up `detail` map —
        // this covers any free-text legacy values the team typed into line
        // items before the field-options table existed.
        $slotsSet = [];
        foreach ($detail as $dt => $daysOfDt) {
            foreach ($daysOfDt as $day => $cats) {
                foreach ($cats as $cat => $blob) {
                    foreach (array_keys($blob['cell'] ?? []) as $slot) {
                        $slotsSet[(string) $slot] = true;
                    }
                }
            }
        }
        $slots = $sortByKnown(array_keys($slotsSet), $slotOrder);

        // ================================================================
        // Targets (soft caps). Returned as two maps so the frontend can
        // render "booked / target" without extra round-trips:
        //   targets.summary[dt][day][category]                 = qty
        //   targets.breakdown[dt][day][category][slot][region] = qty
        // The targets table is a flat (delivery_type, day, category, slot,
        // region, target_qty) shape — summary rows have slot='' & region=''.
        // If the table doesn't exist yet (migration not run) we just return
        // empty maps so the UI keeps working.
        // ================================================================
        $targetSummary = [];
        $targetBreakdown = [];
        if (DB::getSchemaBuilder()->hasTable('t_crm_qurbani_targets')) {
            $targetRows = DB::table('t_crm_qurbani_targets')->get();
            foreach ($targetRows as $tr) {
                $dt  = (string) $tr->delivery_type;
                $day = (string) $tr->day;
                $cat = (string) $tr->category;
                $slot   = (string) ($tr->slot ?? '');
                $region = (string) ($tr->region ?? '');
                $qty = (int) $tr->target_qty;
                if ($slot === '' && $region === '') {
                    $targetSummary[$dt][$day][$cat] = $qty;
                } else {
                    $targetBreakdown[$dt][$day][$cat][$slot][$region] = $qty;
                }
            }
        }

        return response()->json([
            'success' => true,
            'days' => $days,
            'categories' => $categories,
            'slots' => $slots,
            'summary' => $summary,
            'detail' => $detail,
            'targets' => [
                'summary' => $targetSummary,
                'breakdown' => $targetBreakdown,
            ],
        ]);
    }

    /**
     * Upsert Qurbani target quantities (soft caps) sent from the Qurbani
     * orders summary. Accepts a JSON body like:
     *   {
     *     "level": "summary" | "breakdown",
     *     "entries": [
     *       { "delivery_type": "Delivery", "day": "Day 1",
     *         "category": "Goat (Bakra)", "slot": "", "region": "",
     *         "target_qty": 200 }, ...
     *     ]
     *   }
     * Entries with target_qty <= 0 are DELETED instead of upserted, so the
     * UI can "clear" a target simply by setting it to 0. slot/region are
     * kept as empty strings for summary-level rows (see migration notes).
     */
    public function saveQurbaniTargets(Request $request)
    {
        // Targets are a business-planning control — only the Taimur role can
        // create/edit/clear them. Management and others can still VIEW the
        // targets (they ride along on getOrderStats for everyone) but not
        // change them. The summary-card UI hides the "Set Targets" buttons
        // for non-Taimur users as well; this is the authoritative guard.
        $isTaimur = DB::table('t_sys_user_role as ur')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', auth()->id())
            ->whereRaw('LOWER(r.urole_name) = ?', ['taimur'])
            ->exists();
        if (!$isTaimur) {
            return response()->json([
                'success' => false,
                'message' => 'Only the Taimur role can set Qurbani targets.',
            ], 403);
        }

        if (!DB::getSchemaBuilder()->hasTable('t_crm_qurbani_targets')) {
            return response()->json([
                'success' => false,
                'message' => 'Qurbani targets table missing — please run the add_qurbani_targets_apr2026.sql migration first.',
            ], 500);
        }

        $validated = $request->validate([
            'level' => 'required|string|in:summary,breakdown',
            'entries' => 'required|array',
            'entries.*.delivery_type' => 'required|string|max:100',
            'entries.*.day' => 'required|string|max:100',
            'entries.*.category' => 'required|string|max:100',
            'entries.*.slot' => 'nullable|string|max:150',
            'entries.*.region' => 'nullable|string|max:100',
            'entries.*.target_qty' => 'required|integer|min:0|max:100000',
        ]);

        $now = now();
        $savedCount = 0;
        $deletedCount = 0;

        DB::transaction(function () use ($validated, $now, &$savedCount, &$deletedCount) {
            foreach ($validated['entries'] as $e) {
                $slot   = $validated['level'] === 'summary' ? '' : (string) ($e['slot'] ?? '');
                $region = $validated['level'] === 'summary' ? '' : (string) ($e['region'] ?? '');
                $qty = (int) $e['target_qty'];

                $match = [
                    'delivery_type' => $e['delivery_type'],
                    'day'           => $e['day'],
                    'category'      => $e['category'],
                    'slot'          => $slot,
                    'region'        => $region,
                ];

                if ($qty <= 0) {
                    // "Clear" — remove any existing row for this cell.
                    $deletedCount += DB::table('t_crm_qurbani_targets')->where($match)->delete();
                    continue;
                }

                // Try update first; if no row existed yet, insert a fresh one.
                // This keeps the original `created_at` on existing rows
                // (updateOrInsert would overwrite it).
                $affected = DB::table('t_crm_qurbani_targets')
                    ->where($match)
                    ->update([
                        'target_qty' => $qty,
                        'updated_at' => $now,
                    ]);
                if ($affected === 0) {
                    DB::table('t_crm_qurbani_targets')->insert(array_merge($match, [
                        'target_qty' => $qty,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
                }
                $savedCount++;
            }
        });

        return response()->json([
            'success' => true,
            'saved' => $savedCount,
            'deleted' => $deletedCount,
        ]);
    }

    public function getDashboardData(Request $request)
    {
        $hasHistoryTable = DB::getSchemaBuilder()->hasTable('t_crm_history_order');
        $hasHistoryLineItems = DB::getSchemaBuilder()->hasTable('t_crm_history_order_line_item');

        // Current orders tagged qurbani (by attribute or explicit qurbani fields), excluding cancelled
        $currentOrders = DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_order_line_item as li', 'o.id', '=', 'li.order_id')
            ->leftJoin('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
            ->where(function ($q) {
                $q->whereRaw("LOWER(COALESCE(p.attribute_1,'')) = 'qurbani'")
                  ->orWhereNotNull('o.qurbani_day');
            })
            ->where(function ($q) {
                $q->whereNull('o.order_status')
                  ->orWhereRaw("LOWER(o.order_status) <> 'cancelled'");
            })
            ->select(
                DB::raw("YEAR(o.order_date) as year"),
                DB::raw("COUNT(DISTINCT o.id) as order_count"),
                DB::raw("COALESCE(SUM(li.quantity),0) as total_qty"),
                DB::raw("COALESCE(SUM(li.line_total),0) as revenue")
            )
            ->groupBy(DB::raw("YEAR(o.order_date)"))
            ->get()
            ->keyBy('year')
            ->toArray();

        // Historical orders: identify by product name/sku rules
        $historicalOrders = [];
        if ($hasHistoryTable && $hasHistoryLineItems) {
            $historicalOrders = DB::table('t_crm_history_order as ho')
                ->join('t_crm_history_order_line_item as hli', 'ho.id', '=', 'hli.order_id')
                ->where(function ($q) {
                    $q->whereRaw("LOWER(hli.name) LIKE '%qurbani%'")
                      ->orWhereRaw("LOWER(hli.name) LIKE '%hissa%'")
                      ->orWhereRaw("LOWER(COALESCE(hli.sku,'')) LIKE 'qur%'");
                })
                ->select(
                    DB::raw("YEAR(ho.order_date) as year"),
                    DB::raw("COUNT(DISTINCT ho.id) as order_count"),
                    DB::raw("COALESCE(SUM(hli.quantity),0) as total_qty"),
                    DB::raw("COALESCE(SUM(hli.quantity * hli.unit_price),0) as revenue")
                )
                ->groupBy(DB::raw("YEAR(ho.order_date)"))
                ->get()
                ->keyBy('year')
                ->toArray();
        }

        // Merge into combined yearly data
        $allYears = array_unique(array_merge(array_keys($currentOrders), array_keys($historicalOrders)));
        sort($allYears);

        $yearlyData = [];
        foreach ($allYears as $year) {
            $cur = $currentOrders[$year] ?? null;
            $hist = $historicalOrders[$year] ?? null;
            $yearlyData[] = [
                'year' => (int)$year,
                'order_count' => ($cur->order_count ?? 0) + ($hist->order_count ?? 0),
                'total_qty' => ($cur->total_qty ?? 0) + ($hist->total_qty ?? 0),
                'revenue' => round(($cur->revenue ?? 0) + ($hist->revenue ?? 0), 2),
            ];
        }

        // Detailed orders for the selected year
        $selectedYear = $request->get('year', date('Y'));
        $detailedOrders = $this->getYearOrders((int)$selectedYear, $hasHistoryTable, $hasHistoryLineItems);

        return response()->json([
            'success' => true,
            'yearly_summary' => $yearlyData,
            'selected_year' => (int)$selectedYear,
            'detailed_orders' => $detailedOrders,
        ]);
    }

    private function getYearOrders(int $year, bool $hasHistoryTable, bool $hasHistoryLineItems): array
    {
        // Current orders for the year
        $current = DB::table('t_crm_prod_order as o')
            ->leftJoin('t_crm_prod_customer as c', 'o.customer_id', '=', 'c.id')
            ->where(function ($q) {
                $q->whereNotNull('o.qurbani_day')
                  ->orWhereExists(function ($sub) {
                      $sub->select(DB::raw(1))
                          ->from('t_crm_prod_order_line_item as li')
                          ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                          ->whereColumn('li.order_id', 'o.id')
                          ->whereRaw("LOWER(p.attribute_1) = 'qurbani'");
                  });
            })
            ->whereYear('o.order_date', $year)
            ->select(
                'o.id', 'o.order_number', 'o.order_date', 'o.order_status',
                'o.total_price', 'o.payment_status', 'o.total_paid',
                'o.qurbani_day', 'o.qurbani_slot', 'o.qurbani_region', 'o.qurbani_sub_region', 'o.qurbani_delivery_type',
                DB::raw("CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) as customer_name"),
                DB::raw("'current' as source")
            )
            ->orderByDesc('o.order_date')
            ->get()
            ->toArray();

        // Historical orders for the year
        $historical = [];
        if ($hasHistoryTable && $hasHistoryLineItems) {
            $historical = DB::table('t_crm_history_order as ho')
                ->leftJoin('t_crm_prod_customer as c', 'ho.customer_id', '=', 'c.id')
                ->whereYear('ho.order_date', $year)
                ->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_history_order_line_item as hli')
                        ->whereColumn('hli.order_id', 'ho.id')
                        ->where(function ($q) {
                            $q->whereRaw("LOWER(hli.name) LIKE '%qurbani%'")
                              ->orWhereRaw("LOWER(hli.name) LIKE '%hissa%'")
                              ->orWhereRaw("LOWER(COALESCE(hli.sku,'')) LIKE 'qur%'");
                        });
                })
                ->select(
                    'ho.id', 'ho.order_number', 'ho.order_date', 'ho.order_status',
                    'ho.total_price',
                    DB::raw("NULL as payment_status"), DB::raw("NULL as total_paid"),
                    DB::raw("NULL as qurbani_day"), DB::raw("NULL as qurbani_slot"),
                    DB::raw("NULL as qurbani_region"), DB::raw("NULL as qurbani_sub_region"), DB::raw("NULL as qurbani_delivery_type"),
                    DB::raw("CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) as customer_name"),
                    DB::raw("'historical' as source")
                )
                ->orderByDesc('ho.order_date')
                ->get()
                ->toArray();
        }

        $allOrders = array_merge($current, $historical);

        // Attach line items for current orders
        $currentIds = array_column($current, 'id');
        if (!empty($currentIds)) {
            $lineItems = DB::table('t_crm_prod_order_line_item')
                ->whereIn('order_id', $currentIds)
                ->select('order_id', 'name', 'quantity', 'line_total')
                ->get()
                ->groupBy('order_id');
            foreach ($allOrders as &$order) {
                if (($order->source ?? '') === 'current') {
                    $items = $lineItems[$order->id] ?? collect();
                    $order->line_items = $items->map(fn($i) => ['name' => $i->name, 'qty' => $i->quantity, 'total' => $i->line_total])->values()->toArray();
                    $order->total_qty = $items->sum('quantity');
                }
            }
        }

        // Attach line items for historical orders
        $histIds = array_column($historical, 'id');
        if (!empty($histIds) && $hasHistoryLineItems) {
            $hLineItems = DB::table('t_crm_history_order_line_item')
                ->whereIn('order_id', $histIds)
                ->where(function ($q) {
                    $q->whereRaw("LOWER(name) LIKE '%qurbani%'")
                      ->orWhereRaw("LOWER(name) LIKE '%hissa%'")
                      ->orWhereRaw("LOWER(COALESCE(sku,'')) LIKE 'qur%'");
                })
                ->select('order_id', 'name', 'quantity', DB::raw('quantity * unit_price as line_total'))
                ->get()
                ->groupBy('order_id');
            foreach ($allOrders as &$order) {
                if (($order->source ?? '') === 'historical') {
                    $items = $hLineItems[$order->id] ?? collect();
                    $order->line_items = $items->map(fn($i) => ['name' => $i->name, 'qty' => $i->quantity, 'total' => $i->line_total])->values()->toArray();
                    $order->total_qty = $items->sum('quantity');
                }
            }
        }

        // Ensure all orders have defaults
        foreach ($allOrders as &$order) {
            if (!isset($order->line_items)) $order->line_items = [];
            if (!isset($order->total_qty)) $order->total_qty = 0;
        }

        return $allOrders;
    }

    public function getPaymentAccountSummary(Request $request)
    {
        // Aggregate payments for qurbani orders grouped by method + receiving bank.
        // Uses EXISTS for the qurbani check to avoid row multiplication from the
        // line-items join (one payment must not be counted once per line item).
        $cleanTotals = DB::table('t_crm_order_payments as p')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'p.order_id')
            ->where(function ($q) {
                $q->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_prod_order_line_item as li')
                        ->join('t_crm_prod_product as prod', 'prod.id', '=', 'li.product_id')
                        ->whereColumn('li.order_id', 'o.id')
                        ->whereRaw("LOWER(COALESCE(prod.attribute_1,'')) = 'qurbani'");
                })->orWhereNotNull('o.qurbani_day');
            })
            ->where('p.status', 'active')
            ->where(function ($q) {
                $q->whereNull('o.order_status')
                  ->orWhereRaw("LOWER(o.order_status) <> 'cancelled'");
            })
            ->select(
                'p.payment_method',
                'p.receiving_account_id',
                DB::raw("COUNT(p.id) as payment_count"),
                DB::raw("COUNT(DISTINCT o.id) as order_count"),
                DB::raw("SUM(p.amount) as total_amount")
            )
            ->groupBy('p.payment_method', 'p.receiving_account_id')
            ->get();

        $accounts = \App\Models\FIN\OnlineReceivingAccountModel::orderBy('name')->get(['id', 'name', 'short_code', 'color_hex']);
        $accountMap = $accounts->keyBy('id');

        $rows = $cleanTotals->map(function ($r) use ($accountMap) {
            $acct = $r->receiving_account_id ? ($accountMap[$r->receiving_account_id] ?? null) : null;
            return [
                'payment_method' => $r->payment_method,
                'receiving_account_id' => $r->receiving_account_id,
                'account_name' => $acct ? $acct->name : ($r->payment_method === 'online' ? 'Unknown Bank' : null),
                'account_code' => $acct ? $acct->short_code : null,
                'account_color' => $acct ? $acct->color_hex : null,
                'payment_count' => (int) $r->payment_count,
                'order_count' => (int) $r->order_count,
                'total_amount' => round((float) $r->total_amount, 2),
            ];
        });

        $grandTotal = $rows->sum('total_amount');

        return response()->json([
            'success' => true,
            'rows' => $rows->values(),
            'grand_total' => $grandTotal,
        ]);
    }

    public function getPaymentAccountDetails(Request $request)
    {
        $method = $request->query('payment_method');
        $accountId = $request->query('receiving_account_id');

        if (!$method) {
            return response()->json(['success' => false, 'message' => 'payment_method required'], 422);
        }

        $query = DB::table('t_crm_order_payments as p')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'p.order_id')
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('t_fin_online_receiving_accounts as acc', 'acc.id', '=', 'p.receiving_account_id')
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'p.created_by')
            ->where(function ($q) {
                $q->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_prod_order_line_item as li')
                        ->join('t_crm_prod_product as prod', 'prod.id', '=', 'li.product_id')
                        ->whereColumn('li.order_id', 'o.id')
                        ->whereRaw("LOWER(COALESCE(prod.attribute_1,'')) = 'qurbani'");
                })->orWhereNotNull('o.qurbani_day');
            })
            ->where('p.status', 'active')
            ->where(function ($q) {
                $q->whereNull('o.order_status')
                  ->orWhereRaw("LOWER(o.order_status) <> 'cancelled'");
            })
            ->where('p.payment_method', $method);

        if ($accountId === '' || $accountId === null || $accountId === 'null') {
            $query->whereNull('p.receiving_account_id');
        } else {
            $query->where('p.receiving_account_id', $accountId);
        }

        $payments = $query->select(
                'p.id as payment_id',
                'p.amount',
                'p.payment_date',
                'p.reference',
                'p.notes',
                'p.created_at',
                'o.id as order_id',
                'o.order_number',
                DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
                'c.phone as customer_phone',
                'acc.name as account_name',
                'acc.short_code as account_code',
                'u.fullname as created_by_name'
            )
            ->orderBy('p.payment_date', 'desc')
            ->orderBy('p.created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'payments' => $payments,
            'total' => $payments->sum('amount'),
        ]);
    }

    public function toggleQurbaniMode(Request $request)
    {
        $current = ConfigModel::get('qurbani_mode_enabled', '1');
        $newState = $current === '1' ? '0' : '1';
        ConfigModel::set('qurbani_mode_enabled', $newState, 'Enable/disable Qurbani section in web sidebar and mobile');

        return response()->json([
            'success' => true,
            'enabled' => $newState === '1',
            'message' => $newState === '1' ? 'Qurbani mode enabled' : 'Qurbani mode disabled',
        ]);
    }

    public function toggleRiderDelivered(Request $request)
    {
        $current = ConfigModel::get('qurbani_rider_delivered_enabled', '0');
        $newState = $current === '1' ? '0' : '1';
        ConfigModel::set('qurbani_rider_delivered_enabled', $newState, 'Allow riders to mark qurbani orders as delivered');

        return response()->json([
            'success' => true,
            'enabled' => $newState === '1',
            'message' => $newState === '1' ? 'Riders can now mark qurbani orders as delivered' : 'Riders can no longer mark qurbani orders as delivered',
        ]);
    }

    public function toggleDeleteEnabled(Request $request)
    {
        $current = ConfigModel::get('qurbani_delete_enabled', '0');
        $newState = $current === '1' ? '0' : '1';
        ConfigModel::set('qurbani_delete_enabled', $newState, 'Enable/disable order deletion for Qurbani orders (Taimur only)');

        return response()->json([
            'success' => true,
            'enabled' => $newState === '1',
            'message' => $newState === '1' ? 'Order deletion enabled' : 'Order deletion disabled',
        ]);
    }

    public function deleteOrder(Request $request, $id)
    {
        $user = auth()->user();
        $isTaimur = DB::table('t_sys_user_role as ur')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)
            ->whereRaw('LOWER(r.urole_name) = ?', ['taimur'])
            ->exists();
        if (!$isTaimur) {
            return response()->json(['success' => false, 'message' => 'Only Taimur role can delete qurbani orders'], 403);
        }

        $deleteEnabled = ConfigModel::get('qurbani_delete_enabled', '0') === '1';
        if (!$deleteEnabled) {
            return response()->json(['success' => false, 'message' => 'Order deletion is currently disabled in Qurbani Settings'], 403);
        }

        $order = DB::table('t_crm_prod_order')->where('id', $id)->first();
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        DB::beginTransaction();
        try {
            // 1. Reverse payment ledger entries and fix account balances
            $payments = DB::table('t_crm_order_payments')->where('order_id', $id)->where('status', 'active')->get();
            foreach ($payments as $payment) {
                if ($payment->ledger_transaction_id) {
                    $ledger = \App\Models\FIN\LedgerModel::find($payment->ledger_transaction_id);
                    if ($ledger) {
                        $wasApproved = $ledger->approval_status === \App\Models\FIN\LedgerModel::STATUS_APPROVED;
                        if ($wasApproved) {
                            $fromAccount = $ledger->fromAccount;
                            $toAccount = $ledger->toAccount;
                            if ($fromAccount) {
                                $fromAccount->current_balance += $ledger->amount;
                                $fromAccount->save();
                            }
                            if ($toAccount) {
                                $toAccount->current_balance -= $ledger->amount;
                                $toAccount->save();
                            }
                        }
                    }
                }
            }
            // Delete all payments (active + voided)
            DB::table('t_crm_order_payments')->where('order_id', $id)->delete();

            // 2. Reverse any remaining ledger entries for this order (delivery entries etc.)
            $remainingLedgers = \App\Models\FIN\LedgerModel::where('order_id', $id)->get();
            foreach ($remainingLedgers as $ledger) {
                if ($ledger->approval_status === \App\Models\FIN\LedgerModel::STATUS_APPROVED) {
                    $fromAccount = $ledger->fromAccount;
                    $toAccount = $ledger->toAccount;
                    if ($fromAccount) {
                        $fromAccount->current_balance += $ledger->amount;
                        $fromAccount->save();
                    }
                    if ($toAccount) {
                        $toAccount->current_balance -= $ledger->amount;
                        $toAccount->save();
                    }
                }
            }
            DB::table('t_fin_ledger')->where('order_id', $id)->delete();

            // 3. Delete status history
            DB::table('t_crm_order_status_history')->where('order_id', $id)->delete();

            // 4. Delete line items
            DB::table('t_crm_prod_order_line_item')->where('order_id', $id)->delete();

            // 5. Delete discounts
            DB::table('t_crm_order_discounts')->where('order_id', $id)->delete();

            // 6. Delete the order itself
            DB::table('t_crm_prod_order')->where('id', $id)->delete();

            DB::commit();

            \Log::info("Qurbani order deleted", [
                'order_id' => $id,
                'order_number' => $order->order_number,
                'payments_reversed' => $payments->count(),
                'deleted_by' => $user->fullname,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Order #{$order->order_number} deleted successfully along with all related records",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Failed to delete qurbani order", ['order_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to delete order: ' . $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // QURBANI ORDERS — region-wise item-level page (May-2026)
    // =========================================================================
    //
    // The new Qurbani Orders page mirrors the mobile QurbaniOpenOrdersScreen
    // dispatch view: each row is a LINE ITEM (not an invoice), grouped on
    // the client by Status > Region > Sub-region. The smart-box bundle
    // position is computed via the SAME QurbaniBundleService the mobile app
    // uses, so the X/Y on a row matches the mobile pixel-for-pixel.
    //
    // The page also drives the Box Label printer:
    //   - getBoxLabels()        expands line items into per-packet labels
    //   - markBoxesPrinted()    flags packets as printed in t_crm_qurbani_box_print
    //   - unmarkBoxesPrinted()  rolls back a misprint
    //
    // Critical bundle math:
    //   When the user filters by Category=Bakra (or qurbani_type / paya),
    //   the X/Y on the labels MUST reflect the FULL bundle, not the filtered
    //   subset. So a Bakra-only filter on a 2 Bakra + 2 Hissa same-slot
    //   bundle prints "1/4" and "2/4" — never "1/2" and "2/2".
    //
    //   We achieve this by:
    //     1. Running the qualifying-orders query WITHOUT the per-item
    //        filters that could split a bundle (category / qurbani_type /
    //        qurbani_paya) — that gives us every line item in every
    //        affected bundle.
    //     2. Computing bundles via QurbaniBundleService → bundle_size.
    //     3. Expanding to one row per packet (line_item.quantity rows).
    //     4. THEN filtering the expanded list to the user's category /
    //        qurbani_type / qurbani_paya. Position numbers stay frozen.

    public function orders()
    {
        $fieldOptions = DB::table('t_crm_qurbani_field_options')
            ->where('is_active', 1)
            ->orderBy('field_name')
            ->orderBy('display_order')
            ->get()
            ->groupBy('field_name');

        $regions       = ($fieldOptions['qurbani_region'] ?? collect())->pluck('option_value');
        $subRegions    = ($fieldOptions['qurbani_sub_region'] ?? collect())->pluck('option_value');
        $days          = ($fieldOptions['qurbani_day'] ?? collect())->pluck('option_value');
        $slots         = ($fieldOptions['qurbani_slot'] ?? collect())->pluck('option_value');
        $deliveryTypes = ($fieldOptions['qurbani_delivery_type'] ?? collect())->pluck('option_value');
        $qurbaniTypes  = ($fieldOptions['qurbani_type'] ?? collect())->pluck('option_value');
        $payaOptions   = ($fieldOptions['qurbani_paya'] ?? collect())->pluck('option_value');

        $categories = DB::table('t_crm_prod_product')
            ->whereRaw("LOWER(attribute_1) = 'qurbani'")
            ->whereNotNull('attribute_2')
            ->where('attribute_2', '!=', '')
            ->distinct()
            ->orderBy('attribute_2')
            ->pluck('attribute_2');

        // Configurable Qurbani item statuses — same source the mobile uses.
        // Falls back to the canonical 4 if no overrides are configured so
        // the page never renders an empty status filter.
        $statusOptions = DB::table('t_crm_qurbani_field_options')
            ->where('field_name', 'qurbani_item_status')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->pluck('option_value');
        if ($statusOptions->isEmpty()) {
            $statusOptions = collect(['open', 'slaughtered', 'out_for_delivery', 'delivered']);
        }

        $riders = DB::table('t_sys_user as u')
            ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'u.id')
            ->where(function ($q) {
                $q->whereNull('p.user_id')->orWhere('p.active', 1);
            })
            ->where('u.is_active', 1)
            ->orderBy('u.fullname')
            ->select('u.id', 'u.fullname')
            ->get();

        return view('pages.qurbani.orders', compact(
            'regions', 'subRegions', 'days', 'slots', 'deliveryTypes',
            'qurbaniTypes', 'payaOptions', 'categories', 'statusOptions',
            'riders',
            // Full field-option rows (with id/parent_id/delivery_type_parent_id)
            // so the view can drive cascading filters: Slot ⊂ Day×DeliveryType,
            // Sub-Region ⊂ Region. Same source the invoices page uses, so
            // behaviour is consistent across both pages.
            'fieldOptions'
        ));
    }

    /**
     * GET /qurbani/riders
     *
     * Phase 1 (May-2026) — Qurbani Riders dispatch view (web).
     *
     * Read-only landing page that mirrors the mobile QurbaniRidersScreen.
     * The page is a thin shell that fetches /qurbani/api/riders (alias
     * of the mobile getQurbaniRidersSummary endpoint) on load and polls
     * every 30s. Phase 1 is INFORMATIONAL only — Dispatch / Cancel /
     * Auto Route / Edit Route / Live Tracking all remain on the mobile
     * so we don't fork destructive action paths before this read-only
     * shape is validated against real usage.
     *
     * Permission gating happens inside the API endpoint
     * (hasMobilePermission('access_qurbani_mode')). This view method
     * itself just renders the empty shell so that route protection is
     * uniform across the qurbani group via the parent web middleware.
     */
    public function ridersIndex()
    {
        return view('pages.qurbani.riders');
    }

    /**
     * GET /qurbani/riders/{riderId}
     *
     * Phase 1 (May-2026) — Per-rider route detail (web).
     *
     * Read-only mirror of the mobile manager planner
     * (QurbaniRiderRouteScreen). Pulls bundle/dispatch/ETA data from
     * /qurbani/api/riders/{id}/route and the existing
     * /qurbani/api/riders/{id}/dispatch-map endpoint for the map modal.
     * Same Phase-1 constraint as ridersIndex(): informational only —
     * no Dispatch / Cancel / Auto Route / Edit Route / Live Tracking
     * actions here yet.
     */
    public function riderRouteView($riderId)
    {
        return view('pages.qurbani.rider-route', [
            'riderId' => (int) $riderId,
        ]);
    }

    /**
     * GET /qurbani/api/orders-items
     *
     * Returns the flat line-item list with bundle positions, status,
     * rider, and ETA. The client groups by Status > Region > Sub-region.
     */
    public function getOrderItems(Request $request)
    {
        // Step 1: collect candidate orders. We start at the order level so
        // we can apply order-level filters cheaply, then narrow to line
        // items in step 2. Same shape as getInvoices() so behaviour stays
        // consistent.
        $orderQuery = DB::table('t_crm_prod_order as o')
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNotNull('o.qurbani_day')
                          ->orWhereNotNull('o.qurbani_slot')
                          ->orWhereNotNull('o.qurbani_region')
                          ->orWhereNotNull('o.qurbani_delivery_type');
                })
                ->orWhereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_prod_order_line_item as li')
                        ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                        ->whereColumn('li.order_id', 'o.id')
                        ->whereRaw("LOWER(p.attribute_1) = 'qurbani'");
                });
            })
            // Cancelled orders never appear in the dispatch view.
            ->where(function ($q) {
                $q->whereNull('o.order_status')
                  ->orWhereRaw("LOWER(o.order_status) <> 'cancelled'");
            });

        if ($request->filled('customer')) {
            $orderQuery->leftJoin('t_crm_prod_customer as c', 'o.customer_id', '=', 'c.id');
            $customerSearch = $request->customer;
            $orderQuery->where(function ($q) use ($customerSearch) {
                $q->whereRaw("CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) LIKE ?", ["%{$customerSearch}%"])
                  ->orWhere('c.phone', 'LIKE', "%{$customerSearch}%")
                  ->orWhere('o.order_number', 'LIKE', "%{$customerSearch}%");
            });
        }

        $orderIds = $orderQuery->pluck('o.id');
        if ($orderIds->isEmpty()) {
            return response()->json(['success' => true, 'items' => [], 'summary' => $this->emptyItemsSummary()]);
        }

        // Step 2: pull every Qurbani line item in those orders + customer +
        // product + assigned rider. Apply the geographic / scheduling
        // filters at the LINE-ITEM level so a bundle that has both an in-
        // scope and out-of-scope line item still surfaces only the in-scope
        // line items to the user.
        $itemsQuery = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('t_sys_user as r', 'r.id', '=', 'li.qurbani_assigned_rider_user_id')
            ->whereIn('li.order_id', $orderIds)
            // Qualify as Qurbani: either the product is tagged or the line
            // item itself has any qurbani_* metadata.
            ->where(function ($q) {
                $q->whereRaw("LOWER(COALESCE(p.attribute_1, '')) = 'qurbani'")
                  ->orWhereNotNull('li.qurbani_day')
                  ->orWhereNotNull('li.qurbani_slot')
                  ->orWhereNotNull('li.qurbani_region')
                  ->orWhereNotNull('li.qurbani_delivery_type');
            });

        // Helper: scalar-equals + __unassigned__ shorthand. Same convention
        // as getInvoices() so the two pages share a mental model.
        $applyEq = function ($query, string $col, $value) {
            if ($value === '__unassigned__') {
                $query->where(function ($q) use ($col) {
                    $q->whereNull($col)->orWhere($col, '');
                });
            } else {
                $query->where($col, $value);
            }
        };

        if ($request->filled('day'))            $applyEq($itemsQuery, 'li.qurbani_day', $request->day);
        if ($request->filled('slot'))           $applyEq($itemsQuery, 'li.qurbani_slot', $request->slot);
        if ($request->filled('region'))         $applyEq($itemsQuery, 'li.qurbani_region', $request->region);
        if ($request->filled('sub_region'))     $applyEq($itemsQuery, 'li.qurbani_sub_region', $request->sub_region);
        if ($request->filled('delivery_type'))  $applyEq($itemsQuery, 'li.qurbani_delivery_type', $request->delivery_type);
        if ($request->filled('qurbani_type'))   $applyEq($itemsQuery, 'li.qurbani_type', $request->qurbani_type);
        if ($request->filled('qurbani_paya'))   $applyEq($itemsQuery, 'li.qurbani_paya', $request->qurbani_paya);
        if ($request->filled('category'))       $applyEq($itemsQuery, 'p.attribute_2', $request->category);
        if ($request->filled('rider_id')) {
            $itemsQuery->where('li.qurbani_assigned_rider_user_id', (int) $request->rider_id);
        }
        if ($request->filled('status')) {
            $applyEq($itemsQuery, 'li.qurbani_item_status', $request->status);
        }

        $rows = $itemsQuery->select([
                'li.id as line_item_id',
                'li.order_id',
                // Phase 6 (May-2026) — surfaced so the Orders page JS can
                // index per-customer state (currently used by the
                // location-request per-card button to look up the latest
                // request status without an extra round-trip).
                'c.id as customer_id',
                'o.order_number',
                'o.order_status',
                'o.order_date',
                DB::raw("CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) as customer_name"),
                'c.phone as customer_phone',
                // May-2026 — surfaced so the printed Master Sheet can
                // show the real street address instead of the region
                // label. Priority order matches the rest of the
                // dispatch pipeline (see RiderController @ 19267):
                //   1. Order shipping address (address_line1 +
                //      address_line2 + address_city). This is the
                //      address the customer typed at checkout — the
                //      authoritative drop-off location.
                //   2. Customer profile address (address1 + address2)
                //      as a fallback for orders whose shipping fields
                //      weren't populated (legacy / hand-entered).
                //   3. Empty string if neither exists — the front-end
                //      resolver then falls back to qurbani_region so
                //      a row never prints blank.
                //
                // Everything is concatenated server-side with NULLIF
                // / IF guards so blank parts don't introduce stray
                // ", " separators. Other UI surfaces ignore this
                // field and keep using qurbani_region for their
                // on-screen address column.
                // CONCAT_WS skips NULL parts and never inserts a
                // stray separator at the ends, so we don't need a
                // TRIM around it (avoids any MySQL-version quirks
                // with multi-char TRIM remstr). We compute both
                // candidates and use the order's shipping address
                // when ANY of its parts is non-empty, else fall
                // back to the customer profile address.
                DB::raw(
                    "CASE "
                    .   "WHEN COALESCE(o.address_line1, '') <> '' "
                    .     "OR COALESCE(o.address_line2, '') <> '' "
                    .     "OR COALESCE(o.address_city,  '') <> '' "
                    .   "THEN CONCAT_WS(', ', NULLIF(o.address_line1, ''), NULLIF(o.address_line2, ''), NULLIF(o.address_city, '')) "
                    .   "ELSE CONCAT_WS(', ', NULLIF(c.address1, ''), NULLIF(c.address2, '')) "
                    . "END AS customer_address"
                ),
                'c.latitude as cust_lat',
                'c.longitude as cust_lng',
                'c.verified_location_url',
                'c.verified_location_saved_by',
                'c.verified_location_saved_at',
                'li.product_id',
                'li.name as product_name',
                'p.attribute_2 as category_level_2',
                'li.quantity',
                'li.qurbani_day',
                'li.qurbani_slot',
                'li.qurbani_slot_start_minute',
                'li.qurbani_slot_end_minute',
                'li.qurbani_region',
                'li.qurbani_sub_region',
                'li.qurbani_delivery_type',
                'li.qurbani_type',
                'li.qurbani_paya',
                'li.qurbani_item_status',
                'li.qurbani_status_updated_at',
                'li.qurbani_slaughtered_at',
                'li.qurbani_out_for_delivery_at',
                'li.qurbani_delivered_at',
                'li.qurbani_assigned_rider_user_id',
                'r.fullname as rider_name',
                'li.qurbani_delivery_priority',
                'li.qurbani_estimated_delivery_at',
                'li.qurbani_dispatched_at',
                'li.qurbani_started_delivery_at',
                'li.instructions',
            ])
            ->orderByDesc('o.order_date')
            ->orderBy('li.id')
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['success' => true, 'items' => [], 'summary' => $this->emptyItemsSummary()]);
        }

        // Step 3: compute bundles. Critical: pass the FULL set of line
        // items in each affected order so bundle_size is computed against
        // every sibling — even ones the filter excluded (Hissa siblings of
        // a Bakra-only filter still count toward bundle_size).
        $affectedOrderIds = $rows->pluck('order_id')->unique()->values();
        $bundleInputs = DB::table('t_crm_prod_order_line_item as li')
            ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
            ->whereIn('li.order_id', $affectedOrderIds)
            ->where(function ($q) {
                $q->whereRaw("LOWER(COALESCE(p.attribute_1, '')) = 'qurbani'")
                  ->orWhereNotNull('li.qurbani_day')
                  ->orWhereNotNull('li.qurbani_slot')
                  ->orWhereNotNull('li.qurbani_region')
                  ->orWhereNotNull('li.qurbani_delivery_type');
            })
            ->select(
                'li.id', 'li.order_id', 'li.quantity', 'li.product_id',
                'li.qurbani_day', 'li.qurbani_slot', 'li.qurbani_delivery_type',
                'p.attribute_2 as category_level_2'
            )
            ->get()
            ->map(function ($r) {
                return (array) $r;
            })
            ->all();

        $bundleMap = (new QurbaniBundleService())->computeBundles($bundleInputs);

        // Step 4: print log lookup for the whole filtered set so the page
        // can show "1 of 2 boxes printed" against each line item.
        $lineItemIds = $rows->pluck('line_item_id')->all();
        $printLogs = DB::table('t_crm_qurbani_box_print as bp')
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'bp.printed_by')
            ->whereIn('bp.line_item_id', $lineItemIds)
            ->select('bp.line_item_id', 'bp.position', 'bp.bundle_size as printed_bundle_size',
                     'bp.printed_at', 'bp.printed_by', 'u.fullname as printed_by_name')
            ->get()
            ->groupBy('line_item_id');

        // Resolve verifier user ids in one go.
        $verifierIds = $rows->pluck('verified_location_saved_by')->filter()->unique()->all();
        $verifierNames = $verifierIds
            ? DB::table('t_sys_user')->whereIn('id', $verifierIds)->pluck('fullname', 'id')->toArray()
            : [];
        // Customer-app pins are stamped with the sentinel id -> "Customer".
        $verifierNames[\App\Models\CRM\CustomerModel::VERIFIED_PIN_CUSTOMER_ID] = 'Customer';

        // Phase C (May-2026) — pre-load latest GPS reading for every
        // assigned rider so each item card can show a small GPS pill
        // ("Live / Recent / Stale / No GPS") next to the rider chip.
        // One query per page render rather than N+1 against
        // t_ops_rider_location.
        $assignedRiderIds = $rows->pluck('qurbani_assigned_rider_user_id')
            ->filter()->unique()->values()->all();
        $riderGpsMap = [];
        if (!empty($assignedRiderIds)) {
            $gpsRows = DB::table('t_ops_rider_location as rl')
                ->whereIn('rl.user_id', $assignedRiderIds)
                ->whereRaw('rl.captured_at = (SELECT MAX(rl2.captured_at) FROM t_ops_rider_location rl2 WHERE rl2.user_id = rl.user_id)')
                ->select('rl.user_id', 'rl.captured_at')
                ->get();
            $now = now();
            foreach ($gpsRows as $g) {
                $status = 'none';
                $ageMin = null;
                if ($g->captured_at) {
                    try {
                        $ageMin = (int) abs($now->diffInMinutes(\Carbon\Carbon::parse($g->captured_at)));
                        if ($ageMin <= 5)        $status = 'live';
                        elseif ($ageMin <= 30)   $status = 'recent';
                        else                     $status = 'stale';
                    } catch (\Exception $e) { /* leave as none */ }
                }
                $riderGpsMap[(int) $g->user_id] = [
                    'status'      => $status,
                    'age_minutes' => $ageMin,
                    'captured_at' => $g->captured_at ? (string) $g->captured_at : null,
                ];
            }
        }

        // Phase 4 (May-2026) — slot vs ETA / delivered-at compare.
        // Read grace once for all rows; the actual math lives in
        // QurbaniSlotParser::compareEventToSlot().
        $slotCompareGrace = (int) \App\Models\FIN\ConfigModel::get('qurbani_late_grace_minutes', '10');
        if ($slotCompareGrace < 0) $slotCompareGrace = 0;

        // Build the response items.
        $items = [];
        foreach ($rows as $r) {
            $b = $bundleMap[$r->line_item_id] ?? null;
            $printedRows = $printLogs->get($r->line_item_id, collect());
            $printedPositions = $printedRows->pluck('position')->all();
            $qty = max(1, (int) $r->quantity);
            $start = $b['bundle_position_start'] ?? null;
            $end = $b['bundle_position_end'] ?? null;
            $thisLineItemPositions = ($start !== null && $end !== null)
                ? range($start, $end)
                : range(1, $qty);
            $thisLineItemPrinted = array_values(array_intersect($thisLineItemPositions, $printedPositions));

            $items[] = [
                'line_item_id'       => (int) $r->line_item_id,
                'order_id'           => (int) $r->order_id,
                // Phase 6 (May-2026) — passed through so the page JS
                // can wire per-customer state (location-request status
                // pill on the per-card Request Location button).
                'customer_id'        => $r->customer_id ? (int) $r->customer_id : null,
                'order_number'       => $r->order_number,
                'order_status'       => $r->order_status,
                'customer_name'      => trim($r->customer_name) ?: 'Unknown',
                'customer_phone'     => $r->customer_phone,
                // May-2026 — derived in the SELECT (see getOrderItems
                // SQL). Falls back to '' so the front-end resolver
                // can distinguish "field not present" (server-side
                // change not loaded yet) from "field empty" (no
                // address on file).
                'customer_address'   => isset($r->customer_address) ? (string) $r->customer_address : '',
                'product_id'         => $r->product_id,
                'product_name'       => $r->product_name,
                'category_level_2'   => $r->category_level_2,
                'quantity'           => (float) $r->quantity,

                'qurbani_day'           => $r->qurbani_day,
                'qurbani_slot'          => $r->qurbani_slot,
                'qurbani_slot_start_minute' => $r->qurbani_slot_start_minute !== null ? (int) $r->qurbani_slot_start_minute : null,
                'qurbani_slot_end_minute'   => $r->qurbani_slot_end_minute   !== null ? (int) $r->qurbani_slot_end_minute   : null,
                'qurbani_region'        => $r->qurbani_region,
                'qurbani_sub_region'    => $r->qurbani_sub_region,
                'qurbani_delivery_type' => $r->qurbani_delivery_type,
                'qurbani_type'          => $r->qurbani_type,
                'qurbani_paya'          => $r->qurbani_paya,

                'qurbani_item_status'         => $r->qurbani_item_status,
                'qurbani_status_updated_at'   => $r->qurbani_status_updated_at,
                'qurbani_slaughtered_at'      => $r->qurbani_slaughtered_at,
                'qurbani_out_for_delivery_at' => $r->qurbani_out_for_delivery_at,
                'qurbani_delivered_at'        => $r->qurbani_delivered_at,

                'assigned_rider_user_id'      => $r->qurbani_assigned_rider_user_id,
                'assigned_rider_name'         => $r->rider_name,
                // Phase C — GPS health for the assigned rider (or null
                // when unassigned / no GPS reading ever captured).
                'assigned_rider_gps'          => $r->qurbani_assigned_rider_user_id
                    ? ($riderGpsMap[(int) $r->qurbani_assigned_rider_user_id] ?? ['status' => 'none', 'age_minutes' => null, 'captured_at' => null])
                    : null,
                'qurbani_delivery_priority'   => $r->qurbani_delivery_priority,
                'qurbani_estimated_delivery_at' => $r->qurbani_estimated_delivery_at,
                'qurbani_dispatched_at'         => $r->qurbani_dispatched_at,
                'qurbani_started_delivery_at'   => $r->qurbani_started_delivery_at,

                'instructions' => $r->instructions,

                // Bundle math (same as mobile).
                'bundle_key'             => $b['bundle_key'] ?? null,
                'bundle_size'            => $b['bundle_size'] ?? $qty,
                'bundle_position_start'  => $start,
                'bundle_position_end'    => $end,
                'bundle_item_count'      => $b['bundle_item_count'] ?? null,

                // Verified-location info (lets the team see who pinned and when).
                'has_verified_location'      => !empty($r->cust_lat) && !empty($r->cust_lng),
                'cust_lat'                   => $r->cust_lat ? (float) $r->cust_lat : null,
                'cust_lng'                   => $r->cust_lng ? (float) $r->cust_lng : null,
                'verified_location_url'      => $r->verified_location_url,
                'verified_location_saved_by' => $r->verified_location_saved_by,
                'verified_location_saved_by_name' => $r->verified_location_saved_by
                    ? ($verifierNames[$r->verified_location_saved_by] ?? null) : null,
                'verified_location_saved_at' => $r->verified_location_saved_at,

                // Print state on this line item.
                'printed_positions' => $thisLineItemPrinted,
                'print_count'       => count($thisLineItemPrinted),
                'box_count'         => count($thisLineItemPositions),

                // Phase 4 (May-2026) — slot vs ETA / delivered-at.
                // For delivered items: compares actual delivered_at to
                // slot end. For not-yet-delivered items with an ETA:
                // compares ETA to slot end. NULL when there is no slot
                // end on file or no event timestamp to compare against.
                'slot_compare' => (function () use ($r, $slotCompareGrace) {
                    if ($r->qurbani_slot_end_minute === null) return null;
                    if ($r->qurbani_delivered_at) {
                        return \App\Services\QurbaniSlotParser::compareEventToSlot(
                            (string) $r->qurbani_delivered_at,
                            (int) $r->qurbani_slot_end_minute,
                            $slotCompareGrace,
                            'delivered'
                        );
                    }
                    if ($r->qurbani_estimated_delivery_at) {
                        return \App\Services\QurbaniSlotParser::compareEventToSlot(
                            (string) $r->qurbani_estimated_delivery_at,
                            (int) $r->qurbani_slot_end_minute,
                            $slotCompareGrace,
                            'eta'
                        );
                    }
                    return null;
                })(),
            ];
        }

        // Aggregate counters for the UI header.
        $summary = [
            'total_line_items' => count($items),
            'total_boxes'      => array_sum(array_map(fn($i) => $i['box_count'], $items)),
            'total_printed'    => array_sum(array_map(fn($i) => $i['print_count'], $items)),
            'by_status'        => [],
        ];
        foreach ($items as $i) {
            $s = $i['qurbani_item_status'] ?: 'open';
            $summary['by_status'][$s] = ($summary['by_status'][$s] ?? 0) + 1;
        }

        return response()->json([
            'success' => true,
            'items'   => $items,
            'summary' => $summary,
        ]);
    }

    private function emptyItemsSummary(): array
    {
        return [
            'total_line_items' => 0,
            'total_boxes'      => 0,
            'total_printed'    => 0,
            'by_status'        => [],
        ];
    }

    /**
     * GET /qurbani/api/box-labels
     *
     * Returns ONE entry per packet (line_item.quantity rows each), with
     * X/Y reflecting the FULL bundle even when the user filtered narrow.
     *
     * Filtering strategy:
     *   1. Order-level filters (region / day / slot / delivery_type /
     *      sub_region / customer) drop bundles entirely — fine, they
     *      can be applied at SQL level for performance.
     *   2. Item-narrowing filters (category / qurbani_type / qurbani_paya
     *      / status) are applied on the EXPANDED list AFTER bundle math
     *      so the surviving labels still carry the full-bundle X/Y.
     */
    public function getBoxLabels(Request $request)
    {
        // Reuse getOrderItems to get bundle metadata, but call a flat
        // helper that DOESN'T narrow on category / type / paya at the
        // SQL level — we need siblings present in memory.
        $rawItems = $this->collectBoxLabelItems($request);

        // Now apply the narrowing filters in memory (post-bundle).
        $filtered = collect($rawItems)->filter(function ($it) use ($request) {
            if ($request->filled('category') && $request->category !== '__unassigned__') {
                if (($it['category_level_2'] ?? null) !== $request->category) return false;
            } elseif ($request->category === '__unassigned__') {
                if (!empty($it['category_level_2'])) return false;
            }
            if ($request->filled('qurbani_type') && $request->qurbani_type !== '__unassigned__') {
                if (($it['qurbani_type'] ?? null) !== $request->qurbani_type) return false;
            }
            if ($request->filled('qurbani_paya') && $request->qurbani_paya !== '__unassigned__') {
                if (($it['qurbani_paya'] ?? null) !== $request->qurbani_paya) return false;
            }
            if ($request->filled('status') && $request->status !== '__unassigned__') {
                $st = $it['qurbani_item_status'] ?? null;
                if ($st !== $request->status) return false;
            }
            // Default: never print delivered items (they're already
            // delivered, no label needed).
            $includeDelivered = $request->boolean('include_delivered', false);
            if (!$includeDelivered && ($it['qurbani_item_status'] ?? null) === 'delivered') {
                return false;
            }
            return true;
        })->values()->all();

        // Step: expand to per-packet labels, attaching print-log state.
        $lineItemIds = collect($filtered)->pluck('line_item_id')->unique()->all();
        $printLogs = $lineItemIds
            ? DB::table('t_crm_qurbani_box_print as bp')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'bp.printed_by')
                ->whereIn('bp.line_item_id', $lineItemIds)
                ->select('bp.line_item_id', 'bp.position', 'bp.bundle_size as printed_bundle_size',
                         'bp.printed_at', 'bp.printed_by', 'u.fullname as printed_by_name')
                ->get()
                ->groupBy('line_item_id')
            : collect();

        $labels = [];
        foreach ($filtered as $it) {
            $start = $it['bundle_position_start'];
            $end   = $it['bundle_position_end'];
            $size  = $it['bundle_size'];
            $printedByPos = [];
            foreach ($printLogs->get($it['line_item_id'], collect()) as $row) {
                $printedByPos[(int) $row->position] = [
                    'printed_at'      => (string) $row->printed_at,
                    'printed_by'      => (int) $row->printed_by,
                    'printed_by_name' => $row->printed_by_name,
                    'stale'           => ((int) $row->printed_bundle_size !== (int) $size),
                ];
            }
            for ($pos = $start; $pos <= $end; $pos++) {
                $printed = $printedByPos[$pos] ?? null;
                $labels[] = array_merge($it, [
                    'label_id'    => "{$it['line_item_id']}-{$pos}",
                    'position'    => $pos,
                    'bundle_size' => $size,
                    'label_text'  => "{$pos} / {$size}",
                    'is_printed'  => !is_null($printed),
                    'printed_at'  => $printed['printed_at'] ?? null,
                    'printed_by_name' => $printed['printed_by_name'] ?? null,
                    'is_stale_print'  => $printed['stale'] ?? false,
                ]);
            }
        }

        // Sort labels into a sensible print order:
        //   region → sub_region → customer_name → order_number → bundle_key → position
        usort($labels, function ($a, $b) {
            return [
                $a['qurbani_region'] ?? '',
                $a['qurbani_sub_region'] ?? '',
                $a['qurbani_day'] ?? '',
                $a['qurbani_slot'] ?? '',
                $a['customer_name'] ?? '',
                $a['order_number'] ?? '',
                $a['bundle_key'] ?? '',
                $a['position'] ?? 0,
            ] <=> [
                $b['qurbani_region'] ?? '',
                $b['qurbani_sub_region'] ?? '',
                $b['qurbani_day'] ?? '',
                $b['qurbani_slot'] ?? '',
                $b['customer_name'] ?? '',
                $b['order_number'] ?? '',
                $b['bundle_key'] ?? '',
                $b['position'] ?? 0,
            ];
        });

        $totalPrinted = 0;
        foreach ($labels as $l) if ($l['is_printed']) $totalPrinted++;

        return response()->json([
            'success' => true,
            'labels'  => $labels,
            'summary' => [
                'total'   => count($labels),
                'printed' => $totalPrinted,
                'pending' => count($labels) - $totalPrinted,
            ],
        ]);
    }

    /**
     * Helper used by getBoxLabels(). Returns line items with bundle
     * metadata BUT does NOT apply the narrowing filters (category /
     * qurbani_type / qurbani_paya / status / include_delivered).
     */
    private function collectBoxLabelItems(Request $request): array
    {
        // Reuse getOrderItems by stripping the narrowing filters out of
        // the request copy. Cleanest way to keep one source of truth.
        $copy = $request->duplicate();
        $copy->query->remove('category');
        $copy->query->remove('qurbani_type');
        $copy->query->remove('qurbani_paya');
        $copy->query->remove('status');
        $copy->request->remove('category');
        $copy->request->remove('qurbani_type');
        $copy->request->remove('qurbani_paya');
        $copy->request->remove('status');
        $resp = $this->getOrderItems($copy);
        $payload = json_decode($resp->getContent(), true);
        return $payload['items'] ?? [];
    }

    /**
     * POST /qurbani/api/box-print/mark
     * Body: { boxes: [{ line_item_id, position, bundle_size, bundle_key }, ...] }
     *
     * Records each (line_item_id, position) as printed. Idempotent: if
     * the same packet was already printed, the row is updated with the
     * new printed_at / printed_by (re-print scenario).
     */
    public function markBoxesPrinted(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);

        $validated = $request->validate([
            'boxes' => 'required|array|min:1',
            'boxes.*.line_item_id' => 'required|integer|exists:t_crm_prod_order_line_item,id',
            'boxes.*.position'     => 'required|integer|min:1',
            'boxes.*.bundle_size'  => 'required|integer|min:1',
            'boxes.*.bundle_key'   => 'required|string',
        ]);

        // Resolve order_id for each line item in one query (defensive — we
        // could trust client but cheap to verify).
        $lineItemIds = collect($validated['boxes'])->pluck('line_item_id')->unique()->all();
        $orderMap = DB::table('t_crm_prod_order_line_item')
            ->whereIn('id', $lineItemIds)
            ->pluck('order_id', 'id')
            ->toArray();

        $now = now();
        $marked = 0;
        DB::beginTransaction();
        try {
            foreach ($validated['boxes'] as $b) {
                $orderId = $orderMap[$b['line_item_id']] ?? null;
                if (!$orderId) continue;
                // INSERT ... ON DUPLICATE KEY UPDATE: re-prints overwrite
                // the timestamp + bundle_size (in case the bundle grew).
                DB::statement(
                    "INSERT INTO t_crm_qurbani_box_print
                        (order_id, line_item_id, position, bundle_size, bundle_key, printed_at, printed_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        bundle_size = VALUES(bundle_size),
                        bundle_key  = VALUES(bundle_key),
                        printed_at  = VALUES(printed_at),
                        printed_by  = VALUES(printed_by),
                        updated_at  = VALUES(updated_at)",
                    [
                        $orderId, $b['line_item_id'], $b['position'],
                        $b['bundle_size'], $b['bundle_key'],
                        $now, $user->id, $now, $now,
                    ]
                );
                $marked++;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to mark boxes printed', [
                'user_id' => $user->id,
                'count' => count($validated['boxes']),
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'marked'  => $marked,
            'message' => "Marked {$marked} box(es) as printed.",
        ]);
    }

    /**
     * POST /qurbani/api/items/{id}/status
     * Body: { status: 'open'|'slaughtered'|'out_for_delivery'|'delivered'|null }
     *
     * Web-side wrapper around the same logic as the mobile API's
     * bulkUpdateQurbaniItemStatus. Stamps the per-state timeline column
     * the FIRST time the item enters a state (COALESCE keeps the
     * original timestamp on re-entry so audit trail is preserved).
     */
    public function updateItemStatus(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);

        $validated = $request->validate([
            'status' => 'nullable|string|max:50',
        ]);

        $newStatus = $validated['status'] ?? null;
        if ($newStatus === '') $newStatus = null;

        // Validate against configured statuses (matches mobile behavior).
        if ($newStatus !== null) {
            $exists = DB::table('t_crm_qurbani_field_options')
                ->where('field_name', 'qurbani_item_status')
                ->where('option_value', $newStatus)
                ->where('is_active', 1)
                ->exists();
            if (!$exists) {
                return response()->json([
                    'success' => false,
                    'message' => "Unknown qurbani item status: {$newStatus}",
                ], 422);
            }
        }

        $exists = DB::table('t_crm_prod_order_line_item')->where('id', $id)->exists();
        if (!$exists) return response()->json(['success' => false, 'message' => 'Line item not found'], 404);

        $now = now();
        // May-2026 — splice a Carbon-stamped string inside COALESCE
        // (not MySQL's NOW()) so the persisted timestamp matches
        // config('app.timezone') = Asia/Karachi in prod. Previously
        // these columns recorded server-MySQL-TZ (UTC) which made
        // slaughtered_at display ~5h behind the rest of the app and
        // broke the 1-min slaughter trigger comparison in
        // QurbaniWaAutoSender. Safe to splice: toDateTimeString()
        // always returns 'Y-m-d H:i:s' — no quote chars possible.
        $nowStr = $now->toDateTimeString();
        $update = [
            'qurbani_item_status' => $newStatus,
            'qurbani_status_updated_at' => $now,
            'qurbani_status_updated_by' => $user->id,
            'updated_by' => $user->id,
        ];
        // Stamp transition column the FIRST time the item enters that state.
        if ($newStatus === 'slaughtered') {
            $update['qurbani_slaughtered_at'] = DB::raw("COALESCE(qurbani_slaughtered_at, '{$nowStr}')");
        } elseif ($newStatus === 'out_for_delivery') {
            $update['qurbani_out_for_delivery_at'] = DB::raw("COALESCE(qurbani_out_for_delivery_at, '{$nowStr}')");
        } elseif ($newStatus === 'delivered') {
            $update['qurbani_delivered_at'] = DB::raw("COALESCE(qurbani_delivered_at, '{$nowStr}')");
        }

        DB::table('t_crm_prod_order_line_item')->where('id', $id)->update($update);

        return response()->json([
            'success' => true,
            'message' => 'Status updated',
            'status'  => $newStatus,
        ]);
    }

    /**
     * POST /qurbani/api/items/{id}/rider
     * Body: { rider_id: int|null }
     *
     * Web-side single-item rider assignment. Mirrors mobile's
     * bulkAssignQurbaniRider but for one line item.
     */
    public function assignItemRider(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);

        $validated = $request->validate([
            'rider_id' => 'nullable|integer|exists:t_sys_user,id',
        ]);

        $riderId = $validated['rider_id'] ?? null;

        $exists = DB::table('t_crm_prod_order_line_item')->where('id', $id)->exists();
        if (!$exists) return response()->json(['success' => false, 'message' => 'Line item not found'], 404);

        DB::table('t_crm_prod_order_line_item')->where('id', $id)->update([
            'qurbani_assigned_rider_user_id' => $riderId,
            'updated_by' => $user->id,
        ]);

        $riderName = null;
        if ($riderId) {
            $riderName = DB::table('t_sys_user')->where('id', $riderId)->value('fullname');
        }

        return response()->json([
            'success' => true,
            'message' => $riderId ? "Assigned to {$riderName}" : 'Rider cleared',
            'rider_id' => $riderId,
            'rider_name' => $riderName,
        ]);
    }

    /**
     * POST /qurbani/api/box-print/unmark
     * Body: { boxes: [{ line_item_id, position }, ...] }
     *
     * Removes "printed" status from packets — for misprints or reprints
     * the team wants to redo from scratch.
     */
    public function unmarkBoxesPrinted(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);

        $validated = $request->validate([
            'boxes' => 'required|array|min:1',
            'boxes.*.line_item_id' => 'required|integer',
            'boxes.*.position'     => 'required|integer|min:1',
        ]);

        $unmarked = 0;
        foreach ($validated['boxes'] as $b) {
            $unmarked += DB::table('t_crm_qurbani_box_print')
                ->where('line_item_id', $b['line_item_id'])
                ->where('position', $b['position'])
                ->delete();
        }

        \Log::info('Qurbani box prints unmarked', [
            'user_id' => $user->id,
            'count'   => $unmarked,
        ]);

        return response()->json([
            'success'  => true,
            'unmarked' => $unmarked,
            'message'  => "Removed printed status from {$unmarked} box(es).",
        ]);
    }
}
