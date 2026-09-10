<?php

namespace Tests\Feature;

use App\Models\CRM\OrderModel;
use App\Services\CRM\OrderReturnService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RETURNS — the doors, over real HTTP, through the real middleware stack.
 *
 * Only reads and refusals live here on purpose: the money paths are proved in
 * the scripted suites against the replica inside a rolled-back transaction,
 * where balances can be snapshotted account by account. What this file exists to
 * prove is narrower and just as important — that the FOUR doors into "Returned"
 * cannot be opened by anyone the service would refuse.
 *
 * Runs against the dev replica (phpunit.xml leaves DB_CONNECTION alone), so
 * every test is wrapped in a transaction and rolls back.
 */
class OrderReturnGateTest extends TestCase
{
    use DatabaseTransactions;

    private const TAIMUR = 68;   // holds return_orders
    private const RIDER  = 75;   // holds nothing

    /** A delivered, invoiced order that is not on the incremental-payment flow. */
    private function returnableOrderId(): ?int
    {
        return DB::table('t_crm_prod_order as o')
            ->join('t_fin_ledger as l', 'l.id', '=', 'o.ledger_transaction_id')
            ->where('o.order_status', 'delivered')
            ->where('l.approval_status', '!=', 'reversed')
            ->whereNotExists(function ($s) {
                $s->from('t_crm_order_payments')->whereColumn('order_id', 'o.id')->where('status', 'active');
            })
            ->orderByDesc('o.id')
            ->value('o.id');
    }

    private function user(int $id): User
    {
        return User::findOrFail($id);
    }

    public function test_an_authorised_user_gets_the_return_form(): void
    {
        $id = $this->returnableOrderId();
        $this->assertNotNull($id, 'no returnable order in the replica');

        $res = $this->actingAs($this->user(self::TAIMUR), 'web')
            ->getJson("/orders/{$id}/return/preview");

        $res->assertOk()->assertJsonPath('success', true);
        $this->assertTrue($res->json('preview.eligible'), 'order should be eligible');
        $this->assertNotEmpty($res->json('preview.money.options'), 'at least one money answer must be offered');
        $this->assertNotNull($res->json('preview.money.default'), 'one answer must be suggested');
    }

    public function test_a_rider_cannot_read_or_take_a_return(): void
    {
        $id = $this->returnableOrderId();

        $this->actingAs($this->user(self::RIDER), 'web')
            ->getJson("/orders/{$id}/return/preview")
            ->assertStatus(403);

        // The write door is guarded on its own, not just hidden behind the read one.
        $this->actingAs($this->user(self::RIDER), 'web')
            ->postJson("/orders/{$id}/return", [
                'money_action' => 'reversed',
                'goods_action' => 'restock',
            ])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('t_crm_order_return')->where('order_id', $id)->count());
    }

    public function test_the_status_endpoint_refuses_the_returned_code(): void
    {
        $id = $this->returnableOrderId();

        $this->actingAs($this->user(self::TAIMUR), 'web')
            ->postJson('/order-status/api/change-status', [
                'order_id'    => $id,
                'status_code' => OrderReturnService::STATUS_CODE,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'use_return_form');

        $this->assertSame('delivered', OrderModel::find($id)->order_status);
    }

    public function test_returns_are_refused_in_bulk(): void
    {
        $id = $this->returnableOrderId();

        $this->actingAs($this->user(self::TAIMUR), 'web')
            ->postJson('/order-status/api/bulk-change', [
                'order_ids'   => [$id],
                'status_code' => OrderReturnService::STATUS_CODE,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'use_return_form');

        $this->assertSame('delivered', OrderModel::find($id)->order_status);
    }

    public function test_an_ordinary_status_change_still_works(): void
    {
        // The gate must not be collateral damage on the endpoint it lives in.
        $open = DB::table('t_crm_prod_order')
            ->whereIn('order_status', ['processing', 'new'])
            ->orderByDesc('id')
            ->value('id');

        if (!$open) {
            $this->markTestSkipped('no open order in the replica');
        }

        $this->actingAs($this->user(self::TAIMUR), 'web')
            ->postJson('/order-status/api/change-status', [
                'order_id'    => $open,
                'status_code' => 'on_hold',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_a_non_delivered_order_cannot_be_returned(): void
    {
        $open = DB::table('t_crm_prod_order')
            ->whereIn('order_status', ['processing', 'new'])
            ->orderByDesc('id')
            ->value('id');

        if (!$open) {
            $this->markTestSkipped('no open order in the replica');
        }

        $this->actingAs($this->user(self::TAIMUR), 'web')
            ->postJson("/orders/{$open}/return", [
                'money_action' => 'none',
                'goods_action' => 'restock',
            ])
            ->assertStatus(422);
    }

    public function test_the_store_pending_list_answers(): void
    {
        $this->actingAs($this->user(self::TAIMUR), 'web')
            ->getJson('/orders/returns/pending')
            ->assertOk()
            ->assertJsonStructure(['success', 'pending']);
    }

    /**
     * ⭐⭐ THE ROLLBACK GUARANTEE.
     *
     * The deploy sheet says "revoke `return_orders` and every door refuses".
     * That was FALSE for one round: a config email list and a hardcoded
     * taimur|shabib role check sat behind the permission, so revoking it in the
     * Roles UI would have removed nothing for exactly the two people who matter.
     * This test is the guarantee — revoke the permission for THIS user`s roles
     * and the service, the form and the write door must all refuse.
     */
    public function test_revoking_the_permission_really_removes_the_power(): void
    {
        $user = $this->user(self::TAIMUR);
        $svc  = app(OrderReturnService::class);

        $this->assertTrue($svc->userCanReturn($user), "Taimur should hold return_orders to begin with");

        $roleIds = DB::table("t_sys_user_role")->where("user_id", self::TAIMUR)->pluck("role_id");
        DB::table("t_sys_role_permissions")
            ->whereIn("role_id", $roleIds)
            ->where("permission_key", "return_orders")
            ->update(["is_allowed" => 0]);

        // The model caches nothing across instances, but be explicit.
        $fresh = \App\Models\User::findOrFail(self::TAIMUR);
        $this->assertFalse(
            $svc->userCanReturn($fresh),
            "revoking return_orders must remove the power — no email list, no role-name fallback"
        );

        $id = $this->returnableOrderId();
        $this->actingAs($fresh, "web")->getJson("/orders/{$id}/return/preview")->assertStatus(403);
        $this->actingAs($fresh, "web")->postJson("/orders/{$id}/return", [
            "money_action" => "reversed",
            "goods_action" => "wasted",
        ])->assertStatus(403);

        // DatabaseTransactions rolls the revoke back.
    }
    public function test_the_mobile_status_door_refuses_the_code(): void
    {
        $id = $this->returnableOrderId();

        $this->actingAs($this->user(self::TAIMUR), 'sanctum')
            ->postJson('/api/rider/store/update-status', [
                'order_id' => $id,
                'status'   => OrderReturnService::STATUS_CODE,
            ])
            ->assertStatus(422);

        $this->assertSame('delivered', OrderModel::find($id)->order_status);
    }
}
