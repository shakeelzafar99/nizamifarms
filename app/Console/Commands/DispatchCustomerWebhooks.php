<?php

namespace App\Console\Commands;

use App\Services\CustomerAppWebhookDispatcher;
use Illuminate\Console\Command;

/**
 * Dispatcher for the customer-app outbox (t_app_webhook_events).
 *
 * The actual send logic lives in App\Services\CustomerAppWebhookDispatcher so
 * it can be shared by:
 *   - the app()->terminating() hook fired on each order status change
 *     (the primary delivery path — see CustomerAppWebhookEmitter),
 *   - this scheduled command (fallback + retry heartbeat, see routes/console.php),
 *   - manual replay via the flags below.
 *
 * Run modes
 * ---------
 *   php artisan app:dispatch-customer-webhooks
 *       Default: drain pending + due-for-retry rows (lock-protected).
 *
 *   php artisan app:dispatch-customer-webhooks --event-uuid=<uuid>
 *       Replay a single event by uuid, regardless of its current status.
 *
 *   php artisan app:dispatch-customer-webhooks --order=SH-1234
 *       Replay every non-sent row for one order.
 *
 *   php artisan app:dispatch-customer-webhooks --max=200
 *       Override batch size for this run.
 */
class DispatchCustomerWebhooks extends Command
{
    protected $signature = 'app:dispatch-customer-webhooks
                            {--event-uuid= : Replay a single event by its UUID}
                            {--order= : Replay every non-sent row for this NF order_number (e.g. SH-1234)}
                            {--max= : Override batch size for this run}';

    protected $description = 'Drain the customer-app webhook outbox (signed POSTs with retry)';

    public function handle(CustomerAppWebhookDispatcher $dispatcher): int
    {
        if (!$dispatcher->isEnabled()) {
            $this->info('Customer-app webhooks disabled via config — exiting.');
            return self::SUCCESS;
        }

        if ($uuid = $this->option('event-uuid')) {
            $result = $dispatcher->replayByEventUuid($uuid);
        } elseif ($order = $this->option('order')) {
            $result = $dispatcher->replayByOrder($order);
        } else {
            $max = $this->option('max') !== null ? (int) $this->option('max') : null;
            // Lock-protected so it never collides with a terminating() drain.
            $result = $dispatcher->drainPendingSafely($max);
        }

        $this->line(json_encode($result));
        return self::SUCCESS;
    }
}
