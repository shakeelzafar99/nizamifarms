<?php

namespace App\Services\WhatsApp\Automation\Handlers;

/**
 * "Order received — new customer" (rule_key: order_received_new).
 * Fires on order.created only for a customer placing their first-ever order.
 */
class NewCustomerOrderReceivedHandler extends BaseOrderReceivedHandler
{
    protected function isNewLane(): bool
    {
        return true;
    }
}
