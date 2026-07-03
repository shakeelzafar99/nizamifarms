<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use App\Models\CRM\OrderStatusHistory;
use App\Observers\OrderStatusHistoryObserver;
use App\Models\CRM\OrderModel;
use App\Observers\OrderPaymentChangeObserver;
use App\Models\FIN\LedgerModel;
use App\Models\CRM\OrderPaymentModel;
use App\Observers\OrderAuditObserver;
use App\Observers\LedgerAuditObserver;
use App\Observers\OrderPaymentAuditObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register observers for model events
        OrderStatusHistory::observe(OrderStatusHistoryObserver::class);
        // Jun-2026: "bank details on switch to online" automation — catches all
        // payment_method change paths via one OrderModel `updated` hook. Fully
        // guarded + off-by-default; see OrderPaymentChangeObserver.
        OrderModel::observe(OrderPaymentChangeObserver::class);

        // Jul-2026 (Phase 2 L1): audit trail — "who changed what" on the three
        // money-critical models. Each writes ONE fail-safe indexed row via
        // AuditLogger; no-ops if t_sys_audit_log doesn't exist yet.
        OrderModel::observe(OrderAuditObserver::class);
        LedgerModel::observe(LedgerAuditObserver::class);
        OrderPaymentModel::observe(OrderPaymentAuditObserver::class);

        //
        Builder::macro('whereLike', function ($attributes, string $searchTerm) {
            if($searchTerm != ""){
                $this->where(function (Builder $query) use ($attributes, $searchTerm) {
                    foreach (Arr::wrap($attributes) as $attribute) {
                        $query->when(
                            str_contains($attribute, '.'),
                            function (Builder $query) use ($attribute, $searchTerm) {
                                if(count(explode('.', $attribute))>2):
                                
                                [$relationName_1, $relationName_2, $relationAttribute_1] = explode('.', $attribute);
     
                                $query->orWhereHas($relationName_1.'.'.$relationName_2, function (Builder $query) use ($relationAttribute_1, $searchTerm) {
                                    $query->where($relationAttribute_1, 'LIKE', "%{$searchTerm}%");
                                });
                            else:
                                [$relationName, $relationAttribute] = explode('.', $attribute);
     
                                $query->orWhereHas($relationName, function (Builder $query) use ($relationAttribute, $searchTerm) {
                                    $query->where($relationAttribute, 'LIKE', "%{$searchTerm}%");
                                });
                            endif;
                            },
                            function (Builder $query) use ($attribute, $searchTerm) {
                                $query->orWhere($attribute, 'LIKE', "%{$searchTerm}%");
                            }
                        );
                    }
                });
            } 
            return $this;
        });

        Blade::directive('money', function ($amount) {
            return "<?php echo '&pound' . number_format($amount, 2); ?>";
        });  
    }
}
