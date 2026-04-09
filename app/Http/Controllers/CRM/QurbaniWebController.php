<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\FIN\ConfigModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QurbaniWebController extends Controller
{
    public function orders()
    {
        $fieldOptions = DB::table('t_crm_qurbani_field_options')
            ->where('is_active', 1)
            ->orderBy('field_name')
            ->orderBy('display_order')
            ->get()
            ->groupBy('field_name');

        $regions = ($fieldOptions['qurbani_region'] ?? collect())->pluck('option_value');
        $days = ($fieldOptions['qurbani_day'] ?? collect())->pluck('option_value');
        $slots = ($fieldOptions['qurbani_slot'] ?? collect())->pluck('option_value');
        $deliveryTypes = ($fieldOptions['qurbani_delivery_type'] ?? collect())->pluck('option_value');

        $riders = DB::table('t_sys_user as u')
            ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'u.id')
            ->where(function ($q) {
                $q->whereNull('p.user_id')->orWhere('p.active', 1);
            })
            ->where('u.is_active', 1)
            ->orderBy('u.fullname')
            ->select('u.id', 'u.fullname')
            ->get();

        return view('pages.qurbani.orders', compact('regions', 'days', 'slots', 'deliveryTypes', 'riders', 'fieldOptions'));
    }

    public function getOrders(Request $request)
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

        if ($request->filled('region')) {
            $region = $request->region;
            $query->where(function($q) use ($region) {
                $q->where('o.qurbani_region', $region)
                  ->orWhereExists(function($sub) use ($region) {
                      $sub->select(DB::raw(1))
                          ->from('t_crm_prod_order_line_item as fli')
                          ->whereColumn('fli.order_id', 'o.id')
                          ->where('fli.qurbani_region', $region);
                  });
            });
        }
        if ($request->filled('day')) {
            $day = $request->day;
            $query->where(function($q) use ($day) {
                $q->where('o.qurbani_day', $day)
                  ->orWhereExists(function($sub) use ($day) {
                      $sub->select(DB::raw(1))
                          ->from('t_crm_prod_order_line_item as fli')
                          ->whereColumn('fli.order_id', 'o.id')
                          ->where('fli.qurbani_day', $day);
                  });
            });
        }
        if ($request->filled('slot')) {
            $slot = $request->slot;
            $query->where(function($q) use ($slot) {
                $q->where('o.qurbani_slot', $slot)
                  ->orWhereExists(function($sub) use ($slot) {
                      $sub->select(DB::raw(1))
                          ->from('t_crm_prod_order_line_item as fli')
                          ->whereColumn('fli.order_id', 'o.id')
                          ->where('fli.qurbani_slot', $slot);
                  });
            });
        }
        if ($request->filled('delivery_type')) {
            $dtype = $request->delivery_type;
            $query->where(function($q) use ($dtype) {
                $q->where('o.qurbani_delivery_type', $dtype)
                  ->orWhereExists(function($sub) use ($dtype) {
                      $sub->select(DB::raw(1))
                          ->from('t_crm_prod_order_line_item as fli')
                          ->whereColumn('fli.order_id', 'o.id')
                          ->where('fli.qurbani_delivery_type', $dtype);
                  });
            });
        }
        if ($request->filled('status')) {
            $query->where('o.order_status', $request->status);
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
                'o.qurbani_day', 'o.qurbani_slot', 'o.qurbani_region', 'o.qurbani_delivery_type',
                'o.note', 'o.assigned_rider_user_id',
                DB::raw("CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) as customer_name"),
                'c.phone as customer_phone',
                DB::raw("COALESCE(r.fullname,'') as rider_name")
            )
            ->orderByDesc('o.order_date')
            ->limit(500)
            ->get();

        // Attach line items (product names + qty + qurbani fields) for each order
        $orderIds = $orders->pluck('id')->toArray();
        $lineItems = [];
        if (!empty($orderIds)) {
            $lineItems = DB::table('t_crm_prod_order_line_item as li')
                ->leftJoin('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                ->whereIn('li.order_id', $orderIds)
                ->select('li.order_id', 'li.name', 'li.quantity', 'li.line_total', 'li.qurbani_day', 'li.qurbani_slot', 'li.qurbani_region', 'li.qurbani_delivery_type', 'li.instructions', 'p.attribute_2 as category_level_2')
                ->get()
                ->groupBy('order_id');
        }

        // Build active line-item-level filters
        $itemFilters = [];
        if ($request->filled('day')) $itemFilters['qurbani_day'] = $request->day;
        if ($request->filled('slot')) $itemFilters['qurbani_slot'] = $request->slot;
        if ($request->filled('region')) $itemFilters['qurbani_region'] = $request->region;
        if ($request->filled('delivery_type')) $itemFilters['qurbani_delivery_type'] = $request->delivery_type;
        $hasItemFilters = !empty($itemFilters);

        foreach ($orders as &$order) {
            $allItems = $lineItems[$order->id] ?? collect();
            $order->all_items_count = $allItems->count();
            $order->all_items_qty = $allItems->sum('quantity');

            if ($hasItemFilters) {
                $filtered = $allItems->filter(function ($item) use ($itemFilters) {
                    foreach ($itemFilters as $key => $val) {
                        if (($item->$key ?? null) !== $val) return false;
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

    public function getDashboardData(Request $request)
    {
        $hasHistoryTable = DB::getSchemaBuilder()->hasTable('t_crm_history_order');
        $hasHistoryLineItems = DB::getSchemaBuilder()->hasTable('t_crm_history_order_line_item');

        // Current orders tagged qurbani (by attribute or explicit qurbani fields)
        $currentOrders = DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_order_line_item as li', 'o.id', '=', 'li.order_id')
            ->leftJoin('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
            ->where(function ($q) {
                $q->whereRaw("LOWER(COALESCE(p.attribute_1,'')) = 'qurbani'")
                  ->orWhereNotNull('o.qurbani_day');
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
                'o.qurbani_day', 'o.qurbani_slot', 'o.qurbani_region', 'o.qurbani_delivery_type',
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
                    DB::raw("NULL as qurbani_region"), DB::raw("NULL as qurbani_delivery_type"),
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
}
