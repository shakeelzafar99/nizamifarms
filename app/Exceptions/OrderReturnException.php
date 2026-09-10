<?php

namespace App\Exceptions;

/**
 * A return was refused for a reason the person on the screen can act on:
 * "this invoice has already been settled — refund it instead", "record the
 * shop's refund by hand first", "that choice is no longer valid for this order".
 *
 * ⭐ Why its own class rather than a plain RuntimeException:
 * OrderModel::changeStatus() catches everything and returns false, so the text
 * of a failure never reaches the user. We want THESE messages to get through —
 * they are the whole point of the refusal — without also forwarding database
 * errors, which would leak schema detail into a toast. Laravel's QueryException
 * is itself a RuntimeException, so "is it a RuntimeException?" cannot tell the
 * two apart. This class can.
 *
 * Anything thrown as this type is safe to show verbatim. Anything else is not.
 */
class OrderReturnException extends \RuntimeException
{
}
