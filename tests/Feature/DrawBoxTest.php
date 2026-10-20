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
use App\Notifications\DrawExpiringSoon;
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
        $candy = Prize::create(['name' => 'Candy', 'type' => 'constant', 'quantity' => 3]);
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
        Prize::create(['name' => 'Candy', 'type' => 'constant', 'quantity' => 1]);
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
        Prize::create(['name' => 'Candy', 'type' => 'constant', 'quantity' => 1]);
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

    public function test_expiry_and_reminders(): void
    {
        $user = User::factory()->create();
        $old = $this->credit($user, ['expires_at' => now()->subDay()]);
        $soon = $this->credit($user, ['expires_at' => now()->addDay()]);
        $later = $this->credit($user, ['expires_at' => now()->addDays(10)]);

        $credits = app(Credits::class);
        $this->assertSame(1, $credits->expireOverdue());
        $this->assertSame('expired', $old->fresh()->status);

        $this->assertSame(1, $credits->sendReminders());
        $this->assertSame(0, $credits->sendReminders());
        Notification::assertSentToTimes($user, DrawExpiringSoon::class, 1);
        $this->assertNull($later->fresh()->reminded_at);
        $this->assertNotNull($soon->fresh()->reminded_at);
    }

    public function test_supervisor_changes_draw_window(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $this->actingAs($supervisor)->put('/supervisor/settings', ['draw_window_days' => 21, 'reminder_days_before' => 3])->assertRedirect();
        $this->assertSame('21', Setting::get('draw_window_days'));
    }

    public function test_pages_render(): void
    {
        $this->seed();
        $admin = User::factory()->role('admin')->create();
        $credit = $this->credit($admin);
        Prize::where('name', 'Candy')->update(['quantity' => 5]);
        $draw = app(DrawMachine::class)->draw($credit);

        foreach (['/', '/dispatch', '/supervisor', '/supervisor/reports', '/supervisor/inventory', '/supervisor/inventory/log', '/supervisor/settings', '/admin/users', "/draws/{$draw->id}?reveal=1"] as $page) {
            $this->actingAs($admin)->get($page)->assertOk();
        }
        $this->get('/login')->assertRedirect('/');
        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('Send my code');
        $this->get('/')->assertRedirect('/login');
    }
}
