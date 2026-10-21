<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\Draw;
use App\Models\DrawCredit;
use App\Models\GiftCardStock;
use App\Models\LoginCode;
use App\Models\Prize;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\DrawCredited;
use App\Notifications\DrawsExpiringSoon;
use App\Notifications\LoginCode as LoginCodeNotification;
use App\Services\Credits;
use App\Services\DrawMachine;
use App\Services\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DrawBoxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function credit(User $user, array $attrs = []): DrawCredit
    {
        $call = Call::create(['occurred_at' => now(), 'location' => 'Sentara Leigh', 'created_by' => $user->id]);

        return $call->credits()->create($attrs + ['user_id' => $user->id, 'expires_at' => now()->addDays(7)]);
    }

    public function test_login_with_emailed_code(): void
    {
        $user = User::factory()->create(['email' => 'medic@example.com']);
        $code = null;

        $this->post('/login', ['email' => 'Medic@Example.com '])->assertRedirect('/login/code');
        Notification::assertSentTo($user, LoginCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $this->post('/login/code', ['code' => '000000' === $code ? '111111' : '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/login/code', ['code' => $code])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_mail_failure_shows_a_message_instead_of_an_error_page(): void
    {
        $user = User::factory()->create();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));

        $this->from('/login')->post('/login', ['email' => $user->email])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
    }

    public function test_dispatch_still_credits_crew_when_mail_fails(): void
    {
        $dispatcher = User::factory()->role('dispatcher')->create();
        $crew = User::factory()->create();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));

        $this->actingAs($dispatcher)->post('/dispatch', [
            'occurred_at' => now()->subHour()->format('Y-m-d H:i'),
            'location' => 'Sentara Leigh',
            'crew' => [$crew->id],
        ])->assertRedirect('/dispatch');

        $this->assertSame(1, DrawCredit::count());
    }

    public function test_unknown_or_inactive_email_gets_no_code(): void
    {
        $inactive = User::factory()->create(['active' => false]);

        $this->post('/login', ['email' => 'nobody@example.com'])->assertRedirect('/login/code');
        $this->post('/login', ['email' => $inactive->email])->assertRedirect('/login/code');

        Notification::assertNothingSent();
    }

    public function test_code_locks_after_five_wrong_attempts(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email]);
        $code = LoginCode::first();
        $code->update(['code_hash' => bcrypt('123456')]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login/code', ['code' => '654321']);
        }
        $this->post('/login/code', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_dispatch_credits_each_crew_member(): void
    {
        Setting::put('draw_window_days', '3');
        $dispatcher = User::factory()->role('dispatcher')->create();
        [$a, $b] = User::factory()->count(2)->create();

        $this->actingAs($dispatcher)->post('/dispatch', [
            'occurred_at' => now()->subHour()->format('Y-m-d H:i'),
            'location' => 'Chesapeake Regional',
            'unit' => 'Medic 4',
            'crew' => [$a->id, $b->id, $a->id],
        ])->assertRedirect('/dispatch');

        $this->assertSame(2, DrawCredit::count());
        $this->assertTrue(DrawCredit::first()->expires_at->isSameDay(now()->addDays(3)));
        Notification::assertSentTo([$a, $b], DrawCredited::class);
    }

    public function test_employees_cannot_use_dispatch_or_supervisor_pages(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee)->get('/dispatch')->assertForbidden();
        $this->actingAs($employee)->get('/supervisor')->assertForbidden();
        $this->actingAs(User::factory()->role('dispatcher')->create())->get('/supervisor')->assertForbidden();
    }

    public function test_draw_uses_inventory_weighted_odds_and_decrements_items(): void
    {
        $candy = Prize::create(['name' => 'Candy', 'type' => 'item', 'diminishing' => false, 'quantity' => 3]);
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'value_cents' => 6000, 'quantity' => 1]);

        $odds = app(Inventory::class)->odds();
        $this->assertEqualsWithDelta(0.75, $odds[$candy->id]['odds'], 0.0001);
        $this->assertEqualsWithDelta(0.25, $odds[$shears->id]['odds'], 0.0001);

        $user = User::factory()->create();
        $wonShears = false;
        for ($i = 0; $i < 60; $i++) {
            $draw = app(DrawMachine::class)->draw($this->credit($user));
            $wonShears = $wonShears || $draw->prize_id === $shears->id;
        }

        // The single pair of shears can only be won once; candy never runs out.
        $this->assertSame(0, $shears->fresh()->quantity);
        $this->assertSame(3, $candy->fresh()->quantity);
        $this->assertTrue($wonShears);
        $this->assertSame(1, Draw::where('prize_id', $shears->id)->count());
    }

    public function test_credit_can_only_be_drawn_once_and_not_after_expiry(): void
    {
        Prize::create(['name' => 'Candy', 'type' => 'item', 'diminishing' => false, 'quantity' => 1]);
        $user = User::factory()->create();
        $credit = $this->credit($user);

        $this->actingAs($user)->post("/credits/{$credit->id}/draw")->assertRedirect();
        $this->actingAs($user)->post("/credits/{$credit->id}/draw")->assertSessionHas('error');
        $this->assertSame(1, Draw::count());

        $expired = $this->credit($user, ['expires_at' => now()->subMinute()]);
        $this->actingAs($user)->post("/credits/{$expired->id}/draw")->assertSessionHas('error');
        $this->assertSame(1, Draw::count());
    }

    public function test_cannot_draw_someone_elses_credit(): void
    {
        Prize::create(['name' => 'Candy', 'type' => 'item', 'diminishing' => false, 'quantity' => 1]);
        $credit = $this->credit(User::factory()->create());

        $this->actingAs(User::factory()->create())->post("/credits/{$credit->id}/draw")->assertForbidden();
    }

    public function test_gift_card_value_is_reserved_until_brand_is_chosen(): void
    {
        $fifty = Prize::create(['name' => '$50 gift card', 'type' => 'gift_card', 'value_cents' => 5000]);
        $amazon = GiftCardStock::create(['prize_id' => $fifty->id, 'brand' => 'Amazon', 'quantity' => 1]);
        GiftCardStock::create(['prize_id' => $fifty->id, 'brand' => 'Visa', 'quantity' => 0]);
        $inventory = app(Inventory::class);
        $machine = app(DrawMachine::class);

        $this->assertSame(1, $inventory->envelopes()[$fifty->id]['count']);

        $user = User::factory()->create();
        $draw = $machine->draw($this->credit($user));
        $this->assertSame('pending_brand', $draw->status);
        $this->assertSame(0, $inventory->envelopes()[$fifty->id]['count']);
        $this->assertSame(['Amazon'], $machine->brandsFor($draw)->pluck('brand')->all());

        $this->actingAs($user)->post("/draws/{$draw->id}/brand", ['stock_id' => $amazon->id])->assertRedirect();
        $draw->refresh();
        $this->assertSame('won', $draw->status);
        $this->assertSame('Amazon', $draw->brand);
        $this->assertSame(0, $amazon->fresh()->quantity);
    }

    public function test_supervisor_marks_prize_received_and_tax_report_totals(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $user = User::factory()->create(['name' => 'Pat Medic']);
        $prize = Prize::create(['name' => 'Stethoscope', 'type' => 'item', 'value_cents' => 12000, 'quantity' => 1, 'taxable' => true]);
        $draw = app(DrawMachine::class)->draw($this->credit($user));

        // Catalog edits after the draw don't change what was won.
        $prize->update(['value_cents' => 99900]);

        $this->actingAs($supervisor)->post("/supervisor/draws/{$draw->id}/award")->assertRedirect();
        $draw->refresh();
        $this->assertSame('awarded', $draw->status);
        $this->assertSame($supervisor->id, $draw->awarded_by);

        $this->actingAs($supervisor)->get('/supervisor/reports')->assertOk()->assertSee('Pat Medic')->assertSee('$120');
        $csv = $this->actingAs($supervisor)->get('/supervisor/reports/employees.csv')->streamedContent();
        $this->assertStringContainsString('"Pat Medic",,'.$user->email.',1,120.00,0.00,0', $csv);

        $this->actingAs($supervisor)->get('/supervisor')->assertOk()->assertSee('$120');
    }

    public function test_restock_tops_up_to_monthly_amounts(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'quantity' => 1, 'monthly_quantity' => 4]);
        $plenty = Prize::create(['name' => 'Pen light', 'type' => 'item', 'quantity' => 9, 'monthly_quantity' => 4]);
        $card = Prize::create(['name' => '$10 gift card', 'type' => 'gift_card', 'value_cents' => 1000]);
        $stock = GiftCardStock::create(['prize_id' => $card->id, 'brand' => 'Wawa', 'quantity' => 0, 'monthly_quantity' => 5]);

        $this->actingAs($supervisor)->post('/supervisor/inventory/restock')->assertRedirect();

        $this->assertSame(4, $shears->fresh()->quantity);
        $this->assertSame(9, $plenty->fresh()->quantity);
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->actingAs($supervisor)->get('/supervisor/inventory/log')->assertSee('Monthly restock');
    }

    public function test_expiry_and_one_reminder_email_per_employee(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $old = $this->credit($user, ['expires_at' => now()->subDay()]);
        $soon = $this->credit($user, ['expires_at' => now()->addHours(20)]);
        $soonToo = $this->credit($user, ['expires_at' => now()->addHours(23)]);
        $tomorrowish = $this->credit($user, ['expires_at' => now()->addHours(30)]);
        $this->credit($other, ['expires_at' => now()->addHours(5)]);

        $credits = app(Credits::class);
        $this->assertSame(1, $credits->expireOverdue());
        $this->assertSame('expired', $old->fresh()->status);

        $this->assertSame(2, $credits->sendReminders());
        $this->assertSame(0, $credits->sendReminders());
        Notification::assertSentToTimes($user, DrawsExpiringSoon::class, 1);
        Notification::assertSentTo($user, DrawsExpiringSoon::class, fn ($n) => $n->credits->count() === 2);
        Notification::assertSentToTimes($other, DrawsExpiringSoon::class, 1);
        $this->assertNotNull($soon->fresh()->reminded_at);
        $this->assertNotNull($soonToo->fresh()->reminded_at);
        $this->assertNull($tomorrowish->fresh()->reminded_at);
    }

    public function test_reminders_can_be_turned_off(): void
    {
        Setting::put('expiry_reminder_enabled', '0');
        $this->credit(User::factory()->create(), ['expires_at' => now()->addHours(2)]);

        $this->assertSame(0, app(Credits::class)->sendReminders());
        Notification::assertNothingSent();
    }

    public function test_non_diminishing_prizes_are_not_restocked_and_box_value_excludes_won_prizes(): void
    {
        $candy = Prize::create(['name' => 'Candy', 'type' => 'item', 'diminishing' => false, 'value_cents' => 100, 'quantity' => 10, 'monthly_quantity' => 50]);
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'value_cents' => 6000, 'quantity' => 2]);
        $card = Prize::create(['name' => '$50 gift card', 'type' => 'gift_card', 'value_cents' => 5000]);
        GiftCardStock::create(['prize_id' => $card->id, 'brand' => 'Amazon', 'quantity' => 2]);
        $inventory = app(Inventory::class);

        // 10 x $1 + 2 x $60 + 2 x $50
        $this->assertSame(23000, $inventory->boxValueCents());

        $inventory->restock(null);
        $this->assertSame(10, $candy->fresh()->quantity);

        // Take the candy and shears out of the running so the next draw is a gift card.
        $candy->update(['active' => false]);
        $shears->update(['active' => false]);
        $draw = app(DrawMachine::class)->draw($this->credit(User::factory()->create()));
        $this->assertSame('pending_brand', $draw->status);
        $this->assertSame(5000, $inventory->boxValueCents());

        $supervisor = User::factory()->role('supervisor')->create();
        $this->actingAs($supervisor)->get('/supervisor')->assertSee('There is currently a value of', false)->assertSee('$50');
    }

    public function test_supervisor_toggles_diminishing_on_a_prize(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $prize = Prize::create(['name' => 'Snack', 'type' => 'item', 'quantity' => 5]);

        $this->actingAs($supervisor)->put("/supervisor/inventory/prizes/{$prize->id}", [
            'name' => 'Snack', 'type' => 'item', 'value' => 2, 'quantity' => 5,
            'diminishing' => '0', 'taxable' => '0', 'active' => '1',
        ])->assertRedirect();
        $this->assertFalse($prize->fresh()->diminishing);

        $credit = $this->credit(User::factory()->create());
        app(DrawMachine::class)->draw($credit);
        $this->assertSame(5, $prize->fresh()->quantity);
    }

    public function test_supervisor_changes_draw_window(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $this->actingAs($supervisor)->put('/supervisor/settings', ['draw_window_days' => 21, 'expiry_reminder_enabled' => '0'])->assertRedirect();
        $this->assertSame('21', Setting::get('draw_window_days'));
        $this->assertSame('0', Setting::get('expiry_reminder_enabled'));
    }

    public function test_cash_bonus_returns_item_to_box_and_counts_as_taxable(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $user = User::factory()->create();
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'value_cents' => 6000, 'quantity' => 1, 'taxable' => false, 'cash_bonus_allowed' => true]);
        $draw = app(DrawMachine::class)->draw($this->credit($user));
        $this->assertSame(0, $shears->fresh()->quantity);

        $this->actingAs($user)->get("/draws/{$draw->id}")->assertSee('Issue as taxable cash bonus ($60)', false);
        $this->actingAs($user)->post("/draws/{$draw->id}/cash-bonus")->assertRedirect("/draws/{$draw->id}");

        $draw->refresh();
        $this->assertTrue($draw->cash_bonus);
        $this->assertTrue($draw->taxable);
        $this->assertSame(1, $shears->fresh()->quantity);
        $this->assertSame('Cash bonus $60 (instead of Raptor shears)', $draw->label());

        // Only once, and not after it has been paid.
        $this->actingAs($user)->post("/draws/{$draw->id}/cash-bonus")->assertSessionHas('error');
        $this->actingAs($supervisor)->post("/supervisor/draws/{$draw->id}/award");
        $totals = app(\App\Services\Reports::class)->employeeTotals(now()->subDay()->toImmutable(), now()->addDay()->toImmutable());
        $this->assertSame(6000, $totals[0]['taxable_cents']);
        $this->assertSame(6000, $totals[0]['cash_bonus_cents']);
    }

    public function test_cash_bonus_on_gift_card_releases_reserved_or_chosen_card(): void
    {
        $fifty = Prize::create(['name' => '$50 gift card', 'type' => 'gift_card', 'value_cents' => 5000, 'cash_bonus_allowed' => true]);
        $amazon = GiftCardStock::create(['prize_id' => $fifty->id, 'brand' => 'Amazon', 'quantity' => 2]);
        $machine = app(DrawMachine::class);
        $inventory = app(Inventory::class);
        $user = User::factory()->create();

        $pending = $machine->draw($this->credit($user));
        $this->assertSame(1, $inventory->envelopes()[$fifty->id]['count']);
        $machine->takeCashBonus($pending);
        $this->assertSame(2, $inventory->envelopes()[$fifty->id]['count']);

        $chosen = $machine->chooseBrand($machine->draw($this->credit($user)), $amazon->id);
        $this->assertSame(1, $amazon->fresh()->quantity);
        $machine->takeCashBonus($chosen);
        $this->assertSame(2, $amazon->fresh()->quantity);
        $this->assertNull($chosen->fresh()->brand);
    }

    public function test_cash_bonus_not_offered_when_prize_not_eligible(): void
    {
        Prize::create(['name' => 'Stethoscope', 'type' => 'item', 'value_cents' => 9000, 'quantity' => 1]);
        $user = User::factory()->create();
        $draw = app(DrawMachine::class)->draw($this->credit($user));

        $this->actingAs($user)->get("/draws/{$draw->id}")->assertDontSee('Issue as taxable cash bonus');
        $this->actingAs($user)->post("/draws/{$draw->id}/cash-bonus")->assertSessionHas('error');
        $this->assertFalse($draw->fresh()->cash_bonus);
    }

    public function test_admin_can_edit_and_delete_a_won_prize_but_supervisor_cannot(): void
    {
        $admin = User::factory()->role('admin')->create();
        $supervisor = User::factory()->role('supervisor')->create();
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'value_cents' => 6000, 'quantity' => 1]);
        $draw = app(DrawMachine::class)->draw($this->credit(User::factory()->create()));

        $this->actingAs($supervisor)->get("/admin/draws/{$draw->id}/edit")->assertForbidden();
        $this->actingAs($supervisor)->delete("/admin/draws/{$draw->id}")->assertForbidden();
        $this->actingAs($supervisor)->get('/supervisor')->assertDontSee("/admin/draws/{$draw->id}/edit");
        $this->actingAs($admin)->get('/supervisor')->assertSee("/admin/draws/{$draw->id}/edit");

        $this->actingAs($admin)->put("/admin/draws/{$draw->id}", ['prize_id' => $shears->id, 'value' => 55, 'taxable' => '1'])->assertRedirect('/supervisor');
        $this->assertSame(5500, $draw->fresh()->value_cents);
        $this->assertSame(0, $shears->fresh()->quantity);

        $this->actingAs($admin)->delete("/admin/draws/{$draw->id}")->assertRedirect('/supervisor');
        $this->assertNull($draw->fresh());
        $this->assertSame(1, $shears->fresh()->quantity);
        $this->assertSame('void', $draw->credit->fresh()->status);
    }

    public function test_admin_changes_a_won_prize_to_a_gift_card_brand_and_the_box_follows(): void
    {
        $admin = User::factory()->role('admin')->create();
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'value_cents' => 6000, 'quantity' => 1]);
        $draw = app(DrawMachine::class)->draw($this->credit(User::factory()->create()));
        $card = Prize::create(['name' => '$25 gift card', 'type' => 'gift_card', 'value_cents' => 2500, 'cash_bonus_allowed' => true]);
        $amazon = GiftCardStock::create(['prize_id' => $card->id, 'brand' => 'Amazon', 'quantity' => 3]);
        GiftCardStock::create(['prize_id' => $card->id, 'brand' => 'Wawa', 'quantity' => 2]);

        $this->actingAs($admin)->get("/admin/draws/{$draw->id}/edit")->assertOk()->assertSee('$25 gift card')->assertSee('Wawa');

        $this->actingAs($admin)->put("/admin/draws/{$draw->id}", ['prize_id' => $card->id, 'gift_card_stock_id' => $amazon->id, 'value' => 25, 'taxable' => '1'])
            ->assertRedirect('/supervisor');

        $draw->refresh();
        $this->assertSame('$25 gift card', $draw->prize_name);
        $this->assertSame('gift_card', $draw->prize_type);
        $this->assertSame('Amazon', $draw->brand);
        $this->assertSame('won', $draw->status);
        $this->assertSame(2500, $draw->value_cents);
        $this->assertSame(1, $shears->fresh()->quantity);
        $this->assertSame(2, $amazon->fresh()->quantity);

        // Leaving the brand open lets the employee pick, and the Amazon card goes back.
        $this->actingAs($admin)->put("/admin/draws/{$draw->id}", ['prize_id' => $card->id, 'gift_card_stock_id' => '', 'value' => 25, 'taxable' => '1']);
        $this->assertSame('pending_brand', $draw->fresh()->status);
        $this->assertSame(3, $amazon->fresh()->quantity);
    }

    public function test_admin_deletes_a_prize_already_handed_out_from_the_reports(): void
    {
        $admin = User::factory()->role('admin')->create();
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'value_cents' => 6000, 'quantity' => 1]);
        $draw = app(DrawMachine::class)->draw($this->credit(User::factory()->create(['name' => 'Pat Medic'])));
        $this->actingAs($admin)->post(route('supervisor.award', $draw));
        $this->assertSame('awarded', $draw->fresh()->status);

        $this->actingAs($admin)->get('/supervisor/reports')->assertSee("/admin/draws/{$draw->id}/edit");
        $this->actingAs($admin)->get('/supervisor/reports/won')->assertSee("/admin/draws/{$draw->id}/edit");
        $this->actingAs(User::factory()->role('supervisor')->create())->get('/supervisor/reports')->assertDontSee("/admin/draws/{$draw->id}/edit");

        $this->actingAs($admin)->from('/supervisor/reports')->delete("/admin/draws/{$draw->id}")->assertRedirect('/supervisor/reports');
        $this->assertNull($draw->fresh());
        $this->assertSame(0, $shears->fresh()->quantity);
        $this->assertSame(0, app(\App\Services\Reports::class)->wonByEmployee(now()->subDay()->toImmutable(), now()->addDay()->toImmutable())->count());
    }

    public function test_won_list_is_sorted_by_last_name(): void
    {
        Prize::create(['name' => 'Candy', 'type' => 'item', 'diminishing' => false, 'quantity' => 5]);
        $zoe = User::factory()->create(['name' => 'Amy Zimmer']);
        $amy = User::factory()->create(['name' => 'Zoe Adams']);
        app(DrawMachine::class)->draw($this->credit($zoe));
        app(DrawMachine::class)->draw($this->credit($amy));

        $this->actingAs(User::factory()->role('supervisor')->create())->get('/supervisor')
            ->assertSeeInOrder(['Zoe Adams', 'Amy Zimmer']);
    }

    public function test_employees_and_crew_picker_are_in_last_name_order(): void
    {
        $admin = User::factory()->role('admin')->create(['name' => 'Mia Middle']);
        User::factory()->create(['name' => 'Amy Zimmer']);
        User::factory()->create(['name' => 'Zoe Adams']);

        $this->actingAs($admin)->get('/admin/users')->assertSeeInOrder(['Zoe Adams', 'Mia Middle', 'Amy Zimmer']);
        $this->actingAs($admin)->get('/dispatch')->assertSeeInOrder(['Zoe Adams', 'Mia Middle', 'Amy Zimmer'])->assertSee('Type a name to search');
    }

    public function test_won_by_employee_report_separates_pto(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $user = User::factory()->create(['name' => 'Pat Medic']);
        $pto = Prize::create(['name' => 'PTO (2 hours)', 'type' => 'pto', 'pto_hours' => 2, 'value_cents' => 5000, 'quantity' => 1]);
        $machine = app(DrawMachine::class);
        $machine->draw($this->credit($user));
        $pto->update(['active' => false]);
        Prize::create(['name' => 'Stethoscope', 'type' => 'item', 'value_cents' => 9000, 'quantity' => 1]);
        $machine->draw($this->credit($user));

        $rows = app(\App\Services\Reports::class)->wonByEmployee(now()->subDay()->toImmutable(), now()->addDay()->toImmutable());
        $this->assertSame(2, $rows[0]['count']);
        $this->assertSame(9000, $rows[0]['value_cents']);
        $this->assertSame(2.0, $rows[0]['pto_hours']);

        $this->actingAs($supervisor)->get('/supervisor/reports/won')->assertOk()->assertSee('Pat Medic')->assertSee('$90');
        $csv = $this->actingAs($supervisor)->get('/supervisor/reports/won.csv')->streamedContent();
        $this->assertStringContainsString('"Pat Medic TOTAL",,,"2 prizes",90.00,2', $csv);
    }

    public function test_hidden_and_deleted_prizes_leave_the_box_but_keep_history(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'value_cents' => 6000, 'quantity' => 3]);
        $draw = app(DrawMachine::class)->draw($this->credit(User::factory()->create()));
        $card = Prize::create(['name' => '$10 gift card', 'type' => 'gift_card', 'value_cents' => 1000]);
        $wawa = GiftCardStock::create(['prize_id' => $card->id, 'brand' => 'Wawa', 'quantity' => 2]);
        $amazon = GiftCardStock::create(['prize_id' => $card->id, 'brand' => 'Amazon', 'quantity' => 2]);
        $inventory = app(Inventory::class);
        $this->assertSame(4, $inventory->envelopes()[$card->id]['count']);

        $this->actingAs($supervisor)->post("/supervisor/inventory/brands/{$wawa->id}/toggle");
        $this->assertSame(2, $inventory->envelopes()[$card->id]['count']);
        $this->actingAs($supervisor)->get('/supervisor/inventory')->assertSee('Hidden prizes and gift cards (1)');

        $this->actingAs($supervisor)->post("/supervisor/inventory/prizes/{$shears->id}/toggle");
        $this->assertArrayNotHasKey($shears->id, $inventory->envelopes()->all());
        $this->actingAs($supervisor)->post("/supervisor/inventory/prizes/{$shears->id}/toggle");
        $this->assertArrayHasKey($shears->id, $inventory->envelopes()->all());

        $this->actingAs($supervisor)->delete("/supervisor/inventory/brands/{$amazon->id}");
        $this->assertNull($amazon->fresh());
        $this->actingAs($supervisor)->delete("/supervisor/inventory/prizes/{$shears->id}");
        $this->assertSoftDeleted($shears);
        $this->actingAs($supervisor)->get('/supervisor/inventory')->assertDontSee('value="Raptor shears"', false);
        // The past draw still shows its prize.
        $this->actingAs($supervisor)->get('/supervisor/reports')->assertOk()->assertSee($draw->fresh()->prize_name);
    }

    public function test_pages_render(): void
    {
        $this->seed();
        $admin = User::factory()->role('admin')->create();
        $credit = $this->credit($admin);
        Prize::where('name', 'Candy')->update(['quantity' => 5]);
        $draw = app(DrawMachine::class)->draw($credit);

        foreach (['/', '/dispatch', '/supervisor', '/supervisor/reports', '/supervisor/inventory', '/supervisor/inventory/log', '/supervisor/settings', '/admin/users', '/supervisor/reports/won', "/admin/draws/{$draw->id}/edit", "/draws/{$draw->id}?reveal=1"] as $page) {
            $this->actingAs($admin)->get($page)->assertOk();
        }
        $this->get('/login')->assertRedirect('/');
        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('Send my code');
        $this->get('/')->assertRedirect('/login');
    }
}
