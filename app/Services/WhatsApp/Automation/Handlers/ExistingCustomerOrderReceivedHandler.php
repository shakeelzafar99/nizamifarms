<?php

namespace App\Services\WhatsApp\Automation\Handlers;

/**
 * "Order received — existing customer" (rule_key: order_received_existing).
 * Fires on order.created only for a returning customer (one who already has an
 * order with us).
 */
class ExistingCustomerOrderReceivedHandler extends BaseOrderReceivedHandler
{
    protected function isNewLane(): bool
    {
        return false;
    }
}
