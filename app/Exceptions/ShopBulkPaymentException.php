<?php

namespace App\Exceptions;

/**
 * Thrown by ShopBulkPaymentService when a bulk shop payment fails a business
 * rule (bad selection, amount out of range, mixed customers, already-paid
 * invoice, etc.). Controllers catch this and return it as a 422 with the
 * message shown verbatim to the user. Unexpected/technical failures throw
 * ordinary exceptions instead and surface as 500s.
 */
class ShopBulkPaymentException extends \Exception
{
}
