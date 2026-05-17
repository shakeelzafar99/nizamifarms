<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QurbaniSettingsController extends Controller
{
    public function index()
    {
        return view('pages.qurbani.settings');
    }

    public function getOptions()
    {
        $options = DB::table('t_crm_qurbani_field_options')
            ->orderBy('field_name')
            ->orderBy('display_order')
            ->get()
            ->groupBy('field_name');

        $shippingPrice = \App\Models\FIN\ConfigModel::get('qurbani_shipping_price', '1000');
        $defaultPaymentMethod = \App\Models\FIN\ConfigModel::get('qurbani_default_payment_method', 'cash');
        if (!in_array($defaultPaymentMethod, ['cash', 'online'], true)) {
            $defaultPaymentMethod = 'cash';
        }

        $qurbaniCategories = DB::table('t_crm_prod_product')
            ->whereRaw("LOWER(attribute_1) = 'qurbani'")
            ->whereNotNull('attribute_2')
            ->where('attribute_2', '!=', '')
            ->distinct()
            ->orderBy('attribute_2')
            ->pluck('attribute_2')
            ->toArray();

        return response()->json([
            'success' => true,
            'options' => $options,
            'qurbani_shipping_price' => $shippingPrice,
            'qurbani_default_payment_method' => $defaultPaymentMethod,
            'qurbani_categories' => $qurbaniCategories,
        ]);
    }

    public function storeOption(Request $request)
    {
        $validated = $request->validate([
            'field_name' => 'required|string|in:qurbani_day,qurbani_slot,qurbani_region,qurbani_sub_region,qurbani_delivery_type,qurbani_type,qurbani_paya,qurbani_item_status',
            'option_value' => 'required|string|max:100',
            'parent_id' => 'nullable|integer|exists:t_crm_qurbani_field_options,id',
            'delivery_type_parent_id' => 'nullable|integer|exists:t_crm_qurbani_field_options,id',
        ]);

        $maxOrder = DB::table('t_crm_qurbani_field_options')
            ->where('field_name', $validated['field_name'])
            ->max('display_order') ?? 0;

        $query = DB::table('t_crm_qurbani_field_options')
            ->where('field_name', $validated['field_name'])
            ->where('option_value', $validated['option_value']);

        if (isset($validated['parent_id'])) {
            $query->where('parent_id', $validated['parent_id']);
        }
        if (isset($validated['delivery_type_parent_id'])) {
            $query->where('delivery_type_parent_id', $validated['delivery_type_parent_id']);
        } else {
            $query->whereNull('delivery_type_parent_id');
        }

        $existing = $query->first();

        if ($existing) {
            if (!$existing->is_active) {
                $updateData = ['is_active' => 1, 'updated_at' => now()];
                if (isset($validated['parent_id'])) $updateData['parent_id'] = $validated['parent_id'];
                if (isset($validated['delivery_type_parent_id'])) $updateData['delivery_type_parent_id'] = $validated['delivery_type_parent_id'];
                DB::table('t_crm_qurbani_field_options')
                    ->where('id', $existing->id)
                    ->update($updateData);

                return response()->json(['success' => true, 'message' => 'Option re-activated', 'id' => $existing->id]);
            }
            return response()->json(['success' => false, 'message' => 'This option already exists'], 422);
        }

        $id = DB::table('t_crm_qurbani_field_options')->insertGetId([
            'field_name' => $validated['field_name'],
            'option_value' => $validated['option_value'],
            'parent_id' => $validated['parent_id'] ?? null,
            'delivery_type_parent_id' => $validated['delivery_type_parent_id'] ?? null,
            'display_order' => $maxOrder + 1,
            'is_active' => 1,
            'is_default' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Option added', 'id' => $id]);
    }

    public function updateOption(Request $request, $id)
    {
        $validated = $request->validate([
            'option_value' => 'sometimes|string|max:100',
            'display_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'show_in_invoice' => 'sometimes|boolean',
            'parent_id' => 'sometimes|nullable|integer',
            'delivery_type_parent_id' => 'sometimes|nullable|integer',
            'category_override' => 'sometimes|nullable|string|max:100',
        ]);

        $option = DB::table('t_crm_qurbani_field_options')->where('id', $id)->first();
        if (!$option) {
            return response()->json(['success' => false, 'message' => 'Option not found'], 404);
        }

        $update = ['updated_at' => now()];
        if (isset($validated['option_value'])) $update['option_value'] = $validated['option_value'];
        if (isset($validated['display_order'])) $update['display_order'] = $validated['display_order'];
        if (isset($validated['is_active'])) $update['is_active'] = $validated['is_active'] ? 1 : 0;
        if (array_key_exists('parent_id', $validated)) $update['parent_id'] = $validated['parent_id'];
        if (array_key_exists('delivery_type_parent_id', $validated)) $update['delivery_type_parent_id'] = $validated['delivery_type_parent_id'];
        if (array_key_exists('category_override', $validated)) $update['category_override'] = $validated['category_override'];

        if (isset($validated['is_default'])) {
            $newDefault = $validated['is_default'] ? 1 : 0;
            if ($newDefault) {
                // Clear other defaults for the same field + same category scope
                $clearQuery = DB::table('t_crm_qurbani_field_options')
                    ->where('field_name', $option->field_name)
                    ->where('is_default', 1);

                $targetCategory = $validated['category_override'] ?? ($option->category_override ?? null);
                if ($targetCategory) {
                    $clearQuery->where('category_override', $targetCategory);
                } else {
                    $clearQuery->where(function ($q) {
                        $q->whereNull('category_override')->orWhere('category_override', '');
                    });
                }
                $clearQuery->update(['is_default' => 0, 'updated_at' => now()]);
            }
            $update['is_default'] = $newDefault;
        }

        if (isset($validated['show_in_invoice'])) {
            $val = $validated['show_in_invoice'] ? 1 : 0;
            DB::table('t_crm_qurbani_field_options')
                ->where('field_name', $option->field_name)
                ->update(['show_in_invoice' => $val, 'updated_at' => now()]);
            $update['show_in_invoice'] = $val;
        }

        DB::table('t_crm_qurbani_field_options')->where('id', $id)->update($update);

        return response()->json(['success' => true, 'message' => 'Option updated']);
    }

    public function deleteOption($id)
    {
        $option = DB::table('t_crm_qurbani_field_options')->where('id', $id)->first();
        if (!$option) {
            return response()->json(['success' => false, 'message' => 'Option not found'], 404);
        }

        DB::table('t_crm_qurbani_field_options')
            ->where('id', $id)
            ->update(['is_active' => 0, 'updated_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Option deactivated']);
    }

    public function updateShippingPrice(Request $request)
    {
        $validated = $request->validate([
            'price' => 'required|numeric|min:0',
        ]);

        \App\Models\FIN\ConfigModel::set('qurbani_shipping_price', $validated['price'], 'Default delivery fee for qurbani orders');

        return response()->json(['success' => true, 'message' => 'Delivery fee updated', 'price' => $validated['price']]);
    }

    /**
     * Set the default payment method used when a new qurbani order is
     * created (web + mobile read this to pre-select the radio/select).
     * Only 'cash' and 'online' are valid because the Qurbani payment
     * modal is constrained to those two methods.
     */
    public function updateDefaultPaymentMethod(Request $request)
    {
        $validated = $request->validate([
            'method' => 'required|string|in:cash,online',
        ]);

        \App\Models\FIN\ConfigModel::set(
            'qurbani_default_payment_method',
            $validated['method'],
            'Default payment method pre-selected when creating a new qurbani order'
        );

        return response()->json([
            'success' => true,
            'message' => 'Default payment method updated',
            'method' => $validated['method'],
        ]);
    }

    public function updateCancellationCode(Request $request)
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:4',
        ]);

        $code = $validated['code'] ?? '';
        if ($code !== '' && (!ctype_digit($code) || strlen($code) !== 4)) {
            return response()->json(['success' => false, 'message' => 'Code must be exactly 4 digits'], 422);
        }

        \App\Models\FIN\ConfigModel::set(
            'qurbani_cancellation_code',
            $code,
            '4-digit code required to cancel qurbani orders'
        );

        return response()->json([
            'success' => true,
            'message' => $code ? 'Cancellation code saved' : 'Cancellation code removed',
        ]);
    }

    public function reorderOptions(Request $request)
    {
        $validated = $request->validate([
            'field_name' => 'required|string',
            'order' => 'required|array',
            'order.*' => 'integer',
        ]);

        foreach ($validated['order'] as $position => $id) {
            DB::table('t_crm_qurbani_field_options')
                ->where('id', $id)
                ->where('field_name', $validated['field_name'])
                ->update(['display_order' => $position + 1, 'updated_at' => now()]);
        }

        return response()->json(['success' => true, 'message' => 'Order updated']);
    }

    public function updateHiddenCategories(Request $request)
    {
        $validated = $request->validate([
            'hidden' => 'present|array',
            'hidden.*' => 'string',
        ]);

        \App\Models\FIN\ConfigModel::set(
            'qurbani_hidden_stats_categories',
            json_encode($validated['hidden']),
            'Category level-2 names hidden from the booked summary stats table'
        );

        return response()->json([
            'success' => true,
            'message' => 'Stats category visibility updated',
        ]);
    }
}
