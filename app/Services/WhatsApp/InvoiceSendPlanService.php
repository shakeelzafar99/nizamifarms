<?php

namespace App\Services\WhatsApp;

use App\Models\FIN\ConfigModel;
use App\Services\EtaWindowService;
use Illuminate\Support\Facades\DB;

/**
 * SINGLE source of truth for "which invoice template + variables to send for
 * this order". Used by the web Send-Invoice dialog, the mobile Send-Invoice
 * action, and (later) the automatic out-for-delivery sender — so they can never
 * disagree on the template choice.
 *
 * The choice is two-dimensional:
 *   - online vs cash       -> by the order's payment_method
 *   - with-ETA vs no-ETA    -> by whether the delivery window exists yet
 * which selects one of the four `invoice_template_*` config keys (each falling
 * back to the legacy `invoice_dispatch` template until configured).
 *
 * SOURCE-AWARE (the Shopify-staging vs prod id collision): a Shopify-staging id
 * is NEVER looked up in t_crm_prod_order, and the ETA is only ever computed for
 * a dispatched PROD order via EtaWindowService::forOrder().
 */
class InvoiceSendPlanService
{
    /**
     * @return array{template:string, body_params:array, eta:?string, payment_kind:string, name:string, order_number:string, total_price:float}|null
     *         null when the order id doesn't exist in the resolved table.
     */
    public static function for($orderId, bool $isShopify = false): ?array
    {
        $table = $isShopify ? 't_crm_shopify_order' : 't_crm_prod_order';

        $order = DB::table($table)->where('id', (int) $orderId)->first();
        if (!$order) {
            return null;
        }

        $name        = trim((string) ($order->address_first_name ?? '')) ?: 'Customer';
        $orderNumber = $order->order_number ?: ('#' . $order->id);
        $kind        = self::classifyPaymentKind($order->payment_method ?? '');

        // ETA only for a dispatched PROD order — never look a Shopify id up in
        // t_crm_prod_order (id collision).
        $eta = $isShopify ? null : EtaWindowService::forOrder((int) $orderId);

        if ($eta) {
            $key  = $kind === 'cash' ? 'invoice_template_cash_eta' : 'invoice_template_online_eta';
            $body = [$name, (string) $orderNumber, $eta];
        } else {
            $key  = $kind === 'cash' ? 'invoice_template_cash_noeta' : 'invoice_template_online_noeta';
            $body = [$name, (string) $orderNumber];
        }

        $template = ConfigModel::get($key, '') ?: 'invoice_dispatch';

        return [
            'template'     => $template,
            'body_params'  => $body,
            'eta'          => $eta,
            'payment_kind' => $kind,
            'name'         => $name,
            'order_number' => (string) $orderNumber,
            // Authoritative, current total so the Send-Invoice dialog header can
            // show the up-to-date amount instead of trusting stale client-side
            // orders-table row data (a barcode scan can reprice the order
            // seconds before the dialog opens — see the invoice-total-mismatch
            // investigation, WORKLOG 2026-07-06/07).
            'total_price'  => (float) ($order->total_price ?? 0),
        ];
    }

    /**
     * The NO-ETA online/cash template names (the lighter pre-select used by the
     * Send-Invoice dialog before an order id is known). Both fall back to the
     * legacy `invoice_dispatch` template.
     *
     * @return array{online:string, cash:string}
     */
    public static function noEtaMap(): array
    {
        $default = 'invoice_dispatch';
        return [
            'online' => ConfigModel::get('invoice_template_online_noeta', '') ?: $default,
            'cash'   => ConfigModel::get('invoice_template_cash_noeta', '') ?: $default,
        ];
    }

    /** 'cash' if the payment method reads as cash/COD; otherwise 'online'. */
    public static function classifyPaymentKind(?string $pm): string
    {
        $pm = strtolower(trim((string) $pm));
        if ($pm === '') {
            return 'online'; // unknown -> the bank-details message is the safe default
        }
        if (str_contains($pm, 'cash') || str_contains($pm, 'cod')) {
            return 'cash';
        }
        return 'online'; // online / bank_transfer / card / online_payment / etc.
    }
}
