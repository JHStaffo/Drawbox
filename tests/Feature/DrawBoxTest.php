<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\Draw;
use App\Models\DrawCredit;
use App\Models\GiftCardStock;
use App\Models\Locker;
use App\Models\LockerAssignment;
use App\Models\LoginCode;
use App\Models\Prize;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\DrawCredited;
use App\Notifications\DrawsExpiringSoon;
use App\Notifications\LoginCode as LoginCodeNotification;
use App\Notifications\PrizesInLocker;
use App\Services\Credits;
use App\Services\DrawMachine;
use App\Services\Inventory;
use App\Services\Lockers;
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

    public function test_new_draw_email_retries_when_mail_fails(): void
    {
        $crew = User::factory()->create();
        $credit = $this->credit($crew);
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));

        $this->assertSame(0, app(Credits::class)->sendNewDrawNotices());
        $this->assertNull($credit->fresh()->notified_at);
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
        Notification::assertNothingSent();

        $this->artisan('drawbox:notify')->assertSuccessful();
        Notification::assertSentTo([$a, $b], DrawCredited::class);
    }

    public function test_new_draw_emails_are_batched_per_team_member(): void
    {
        Prize::create(['name' => 'Candy', 'type' => 'item', 'diminishing' => false, 'quantity' => 5]);
        $crew = User::factory()->create();
        $this->credit($crew);
        $this->credit($crew);
        $alreadyDrawn = $this->credit($other = User::factory()->create());
        $this->actingAs($other)->post("/credits/{$alreadyDrawn->id}/draw");

        $this->assertSame(1, app(Credits::class)->sendNewDrawNotices());
        Notification::assertSentToTimes($crew, DrawCredited::class, 1);
        Notification::assertSentTo($crew, DrawCredited::class, fn ($n) => $n->credits->count() === 2);
        Notification::assertNotSentTo($other, DrawCredited::class);

        $this->assertSame(0, app(Credits::class)->sendNewDrawNotices());
    }

    public function test_dispatch_can_undo_a_call_until_someone_draws(): void
    {
        Prize::create(['name' => 'Candy', 'type' => 'item', 'diminishing' => false, 'quantity' => 5]);
        $dispatcher = User::factory()->role('dispatcher')->create();
        $crew = User::factory()->create(['name' => 'Sam Crew']);

        $mistake = $this->credit($crew)->call;
        $this->actingAs($dispatcher)->get('/dispatch')->assertSee('Undo');
        $this->actingAs($dispatcher)->delete("/dispatch/calls/{$mistake->id}")->assertRedirect('/dispatch');
        $this->assertModelMissing($mistake);
        $this->assertSame(0, DrawCredit::count());

        $credit = $this->credit($crew);
        $this->actingAs($crew)->post("/credits/{$credit->id}/draw");
        $this->actingAs($dispatcher)->get('/dispatch')->assertSee('Already drawn')->assertDontSee('>Undo<', false);
        $this->actingAs($dispatcher)->delete("/dispatch/calls/{$credit->call_id}")->assertSessionHasErrors('undo');
        $this->assertSame(1, Draw::count());

        $this->actingAs($crew)->delete("/dispatch/calls/{$credit->call_id}")->assertForbidden();
    }

    public function test_only_admins_reach_reports_inventory_and_settings(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $admin = User::factory()->role('admin')->create();
        foreach (['/supervisor/reports', '/supervisor/reports/won', '/supervisor/inventory', '/supervisor/inventory/log', '/supervisor/settings'] as $page) {
            $this->actingAs($supervisor)->get($page)->assertForbidden();
            $this->actingAs($admin)->get($page)->assertOk();
        }
        $this->actingAs($supervisor)->post('/supervisor/inventory/reset')->assertForbidden();
        $this->actingAs($supervisor)->put('/supervisor/settings', ['draw_window_days' => 3])->assertForbidden();

        $this->actingAs($supervisor)->get('/supervisor')->assertOk()
            ->assertDontSee('>Reports<', false)->assertDontSee('>Inventory<', false)->assertDontSee('>Settings<', false);
        $this->actingAs($admin)->get('/supervisor')->assertSee('>Reports<', false);
    }

    public function test_admin_adds_several_brands_at_once(): void
    {
        $admin = User::factory()->role('admin')->create();
        $card = Prize::create(['name' => '$10 gift card', 'type' => 'gift_card', 'value_cents' => 1000]);
        GiftCardStock::create(['prize_id' => $card->id, 'brand' => 'Wawa', 'quantity' => 1]);

        $this->actingAs($admin)->post("/supervisor/inventory/prizes/{$card->id}/brands", ['items' => [
            ['brand' => 'Amazon', 'quantity' => 3],
            ['brand' => '', 'quantity' => 0],
            ['brand' => ' Target ', 'quantity' => 2],
        ]])->assertSessionHas('status', 'Added Amazon and Target to $10 gift card.');
        $this->assertSame([1, 3, 2], $card->stocks()->reorder('id')->pluck('quantity')->all());
        // New cards go into the safe count only; draws left come from the monthly amount.
        $this->assertSame(0, $card->fresh()->available);

        // A name already on the card, or entered twice, saves nothing.
        $this->actingAs($admin)->post("/supervisor/inventory/prizes/{$card->id}/brands", ['items' => [
            ['brand' => 'Visa', 'quantity' => 1], ['brand' => 'wawa', 'quantity' => 1],
        ]])->assertSessionHasErrors('items.1.brand');
        $this->actingAs($admin)->post("/supervisor/inventory/prizes/{$card->id}/brands", ['items' => [
            ['brand' => 'Visa', 'quantity' => 1], ['brand' => 'VISA', 'quantity' => 1],
        ]])->assertSessionHasErrors();
        $this->actingAs($admin)->post("/supervisor/inventory/prizes/{$card->id}/brands", ['items' => [['brand' => '']]])->assertSessionHasErrors('items');
        $this->assertSame(3, $card->stocks()->count());
    }

    public function test_candy_with_choices_keeps_its_odds_and_tracks_each_choice(): void
    {
        $admin = User::factory()->role('admin')->create();
        $candy = Prize::create(['name' => 'Candy', 'type' => 'item', 'diminishing' => false, 'quantity' => 10]);
        $this->actingAs($admin)->post("/supervisor/inventory/prizes/{$candy->id}/brands", ['items' => [
            ['brand' => 'Snickers', 'quantity' => 1],
            ['brand' => 'Twix', 'quantity' => 0],
        ]])->assertSessionHas('status');
        $inventory = app(Inventory::class);
        $this->assertSame(10, $inventory->envelopes()[$candy->id]['count']);
        $this->actingAs($admin)->get('/supervisor/inventory')->assertOk()->assertSee('Prize choices')->assertSee('Snickers');

        $user = User::factory()->create();
        $credit = $this->credit($user);
        $this->actingAs($user)->post("/credits/{$credit->id}/draw");
        $draw = Draw::first();
        $this->assertSame('pending_brand', $draw->status);
        $this->actingAs($user)->get('/')->assertSee('Pick a choice');
        $this->actingAs($user)->get("/draws/{$draw->id}")->assertSee('Pick the choice')->assertSee('Snickers')->assertDontSee('>Twix<', false);
        // The last Snickers is held for this winner, so Candy can't be drawn again until more are added.
        $this->assertSame(0, $inventory->envelopes()[$candy->id]['count']);

        $snickers = GiftCardStock::where('brand', 'Snickers')->first();
        $this->actingAs($user)->post("/draws/{$draw->id}/brand", ['stock_id' => $snickers->id])->assertRedirect();
        $this->assertSame('Candy (Snickers)', $draw->fresh()->label());
        $this->assertSame(0, $snickers->fresh()->quantity);
        $this->assertSame(10, $candy->fresh()->quantity);

        $this->actingAs($admin)->put("/supervisor/inventory/brands/{$snickers->id}", ['quantity' => 6]);
        $this->assertSame(6, $snickers->fresh()->quantity);
        $this->assertSame(10, $inventory->envelopes()[$candy->id]['count']);
    }

    public function test_diminishing_prize_with_choices_counts_its_choices(): void
    {
        $admin = User::factory()->role('admin')->create();
        $light = Prize::create(['name' => 'Pen light', 'type' => 'item', 'quantity' => 0, 'available' => 3]);
        $red = GiftCardStock::create(['prize_id' => $light->id, 'brand' => 'Red', 'quantity' => 2]);
        GiftCardStock::create(['prize_id' => $light->id, 'brand' => 'Blue', 'quantity' => 1]);
        $inventory = app(Inventory::class);
        $this->assertSame(3, $inventory->envelopes()[$light->id]['count']);

        $draw = app(DrawMachine::class)->draw($this->credit(User::factory()->create()));
        $this->assertSame('pending_brand', $draw->status);
        $this->assertSame(2, $inventory->envelopes()[$light->id]['count']);
        app(DrawMachine::class)->chooseBrand($draw, $red->id);
        $this->assertSame(1, $red->fresh()->quantity);
        $this->assertSame(0, $light->fresh()->quantity);

        // Removing the prize puts the picked choice and the draw back, not the prize's own count.
        app(DrawMachine::class)->removeDraw($draw->fresh(), $admin);
        $this->assertSame(2, $red->fresh()->quantity);
        $this->assertSame(0, $light->fresh()->quantity);
        $this->assertSame(3, $light->fresh()->available);
        $this->actingAs($admin)->get('/supervisor/inventory')->assertOk()->assertSee('choices</a>', false);
    }

    public function test_admin_deletes_a_team_member_added_by_mistake_but_not_one_with_history(): void
    {
        $admin = User::factory()->role('admin')->create();
        $mistake = User::factory()->create(['name' => 'Wrong Person']);
        $unused = $this->credit($mistake);
        $unused->call->update(['created_by' => $admin->id]); // dispatch credited them by mistake
        Prize::create(['name' => 'Candy', 'type' => 'item', 'diminishing' => false, 'quantity' => 5]);
        $winner = User::factory()->create(['name' => 'Real Medic']);
        $this->actingAs($winner)->post("/credits/{$this->credit($winner)->id}/draw");

        $this->actingAs($admin)->get('/admin/users')->assertSee("/admin/users/{$mistake->id}\"", false);
        $this->actingAs($admin)->delete("/admin/users/{$mistake->id}")->assertSessionHas('status', 'Deleted Wrong Person.');
        $this->assertModelMissing($mistake);
        $this->assertModelMissing($unused);

        $this->actingAs($admin)->delete("/admin/users/{$winner->id}")->assertSessionHas('error');
        $this->assertModelExists($winner);
        $this->actingAs($admin)->delete("/admin/users/{$admin->id}")->assertSessionHas('error');
        $this->actingAs(User::factory()->role('supervisor')->create())->delete("/admin/users/{$winner->id}")->assertForbidden();
    }

    public function test_pto_winner_is_told_hours_go_to_their_pto_bank(): void
    {
        Prize::create(['name' => 'PTO (2 hours)', 'type' => 'pto', 'pto_hours' => 2, 'value_cents' => 5000, 'quantity' => 1]);
        $user = User::factory()->create();
        $draw = app(DrawMachine::class)->draw($this->credit($user));

        $this->actingAs($user)->get("/draws/{$draw->id}")
            ->assertSee('2 Hours of PTO will be added to your PTO bank.')
            ->assertDontSee('A supervisor will hand it to you');
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
        $fifty = Prize::create(['name' => '$50 gift card', 'type' => 'gift_card', 'value_cents' => 5000, 'available' => 1]);
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

        $admin = User::factory()->role('admin')->create();
        $this->actingAs($supervisor)->get('/supervisor/reports')->assertForbidden();
        $this->actingAs($admin)->get('/supervisor/reports')->assertOk()->assertSee('Pat Medic')->assertSee('$120');
        $csv = $this->actingAs($admin)->get('/supervisor/reports/employees.csv')->streamedContent();
        $this->assertStringContainsString('"Pat Medic",,'.$user->email.',1,120.00,0.00,0', $csv);

        $this->actingAs($supervisor)->get('/supervisor')->assertOk()->assertSee('$120');
    }

    public function test_draws_left_and_on_hand_follow_cash_bonuses_and_reset(): void
    {
        $admin = User::factory()->role('admin')->create();
        $card = Prize::create(['name' => '$10 gift card', 'type' => 'gift_card', 'value_cents' => 1000, 'cash_bonus_allowed' => true, 'available' => 3]);
        $kroger = GiftCardStock::create(['prize_id' => $card->id, 'brand' => 'Kroger', 'quantity' => 3]);
        $machine = app(DrawMachine::class);
        $inventory = app(Inventory::class);
        $left = fn () => $inventory->envelopes()[$card->id]['left'].'/'.$inventory->envelopes()[$card->id]['on_hand'];
        $this->actingAs($admin)->get('/supervisor/inventory')->assertSee('3/3 left');

        // A cash bonus uses up a draw but the card stays on hand.
        $machine->takeCashBonus($machine->draw($this->credit(User::factory()->create())));
        $this->assertSame('2/3', $left());

        // A card actually taken lowers both.
        $machine->chooseBrand($machine->draw($this->credit(User::factory()->create())), $kroger->id);
        $this->assertSame('1/2', $left());
        $this->assertSame(1, $inventory->envelopes()[$card->id]['count']);

        // At 0 draws left the prize is out of the drawing even with cards on hand.
        $this->actingAs($admin)->from('/supervisor/inventory')->put("/supervisor/inventory/prizes/{$card->id}", [
            'name' => $card->name, 'type' => 'gift_card', 'value' => 10, 'cash_bonus_allowed' => '1', 'taxable' => '0', 'available' => 0,
        ])->assertRedirect('/supervisor/inventory');
        $this->assertSame('0/2', $left());
        $this->assertSame(0, $inventory->envelopes()[$card->id]['count']);

        // The safe holds 32 cards but only 8 a month are up for draw.
        $this->actingAs($admin)->put("/supervisor/inventory/brands/{$kroger->id}", ['quantity' => 32]);
        $this->assertSame('0/32', $left());
        $this->actingAs($admin)->put("/supervisor/inventory/prizes/{$card->id}", [
            'name' => $card->name, 'type' => 'gift_card', 'value' => 10, 'cash_bonus_allowed' => '1', 'taxable' => '0', 'available' => 0, 'monthly_quantity' => 8,
        ]);

        // Reset sets draws left to each prize's monthly amount, never what's on hand.
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'quantity' => 2, 'monthly_quantity' => 4, 'available' => 0]);
        $candy = Prize::create(['name' => 'Candy', 'type' => 'item', 'diminishing' => false, 'quantity' => 10, 'available' => 0]);
        $this->actingAs($admin)->post('/supervisor/inventory/reset')->assertSessionHas('status');
        $this->assertSame('8/32', $left());
        $this->assertSame(8, $inventory->envelopes()[$card->id]['count']);
        $this->assertSame(4, $shears->fresh()->available);
        // Only 2 shears are on hand, so only 2 can be drawn.
        $this->assertSame(2, $inventory->envelopes()[$shears->id]['count']);
        $this->assertSame(0, $candy->fresh()->available);
        $this->assertSame(10, $inventory->envelopes()[$candy->id]['count']);
        $this->actingAs($admin)->get('/supervisor/inventory')->assertSee('8/32 left');
        $this->actingAs($admin)->get('/supervisor/inventory/log')->assertSee('Monthly reset')->assertSee('(draws left)');
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

    public function test_non_diminishing_prizes_are_not_reset_and_box_value_excludes_won_prizes(): void
    {
        $candy = Prize::create(['name' => 'Candy', 'type' => 'item', 'diminishing' => false, 'value_cents' => 100, 'quantity' => 10]);
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'value_cents' => 6000, 'quantity' => 2]);
        $card = Prize::create(['name' => '$50 gift card', 'type' => 'gift_card', 'value_cents' => 5000, 'monthly_quantity' => 2]);
        GiftCardStock::create(['prize_id' => $card->id, 'brand' => 'Amazon', 'quantity' => 2]);
        $inventory = app(Inventory::class);

        // 10 x $1 + 2 x $60 + 2 x $50
        $this->assertSame(23000, $inventory->boxValueCents());

        $inventory->resetAvailable(null);
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

    public function test_admin_toggles_diminishing_on_a_prize(): void
    {
        $admin = User::factory()->role('admin')->create();
        $prize = Prize::create(['name' => 'Snack', 'type' => 'item', 'quantity' => 5]);

        $this->actingAs($admin)->put("/supervisor/inventory/prizes/{$prize->id}", [
            'name' => 'Snack', 'type' => 'item', 'value' => 2, 'quantity' => 5,
            'diminishing' => '0', 'taxable' => '0', 'active' => '1',
        ])->assertRedirect();
        $this->assertFalse($prize->fresh()->diminishing);

        $credit = $this->credit(User::factory()->create());
        app(DrawMachine::class)->draw($credit);
        $this->assertSame(5, $prize->fresh()->quantity);
    }

    public function test_saving_quantities_from_the_inventory_page(): void
    {
        $admin = User::factory()->role('admin')->create();
        $candy = Prize::create(['name' => 'Candy', 'type' => 'item', 'diminishing' => false, 'quantity' => 5]);
        $card = Prize::create(['name' => '$10 gift card', 'type' => 'gift_card', 'value_cents' => 1000]);
        $stock = GiftCardStock::create(['prize_id' => $card->id, 'brand' => 'Wawa', 'quantity' => 1]);

        // Exactly the fields the inventory page posts for each row.
        $this->actingAs($admin)->from('/supervisor/inventory')
            ->put("/supervisor/inventory/brands/{$stock->id}", ['quantity' => 4])
            ->assertRedirect('/supervisor/inventory');
        $this->assertSame(4, $stock->fresh()->quantity);

        $this->actingAs($admin)->from('/supervisor/inventory')
            ->put("/supervisor/inventory/prizes/{$candy->id}", ['type' => 'item', 'name' => 'Candy', 'value' => 0, 'quantity' => 9, 'diminishing' => '0', 'cash_bonus_allowed' => '0', 'taxable' => '0'])
            ->assertRedirect('/supervisor/inventory');
        $this->assertSame(9, $candy->fresh()->quantity);
    }

    public function test_admin_changes_draw_window(): void
    {
        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin)->put('/supervisor/settings', ['draw_window_days' => 21, 'locker_pickup_days' => 10, 'expiry_reminder_enabled' => '0'])->assertRedirect();
        $this->assertSame('21', Setting::get('draw_window_days'));
        $this->assertSame('10', Setting::get('locker_pickup_days'));
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
        $fifty = Prize::create(['name' => '$50 gift card', 'type' => 'gift_card', 'value_cents' => 5000, 'cash_bonus_allowed' => true, 'available' => 2]);
        $amazon = GiftCardStock::create(['prize_id' => $fifty->id, 'brand' => 'Amazon', 'quantity' => 2]);
        $machine = app(DrawMachine::class);
        $inventory = app(Inventory::class);
        $user = User::factory()->create();

        $pending = $machine->draw($this->credit($user));
        $this->assertSame(1, $inventory->envelopes()[$fifty->id]['count']);
        $machine->takeCashBonus($pending);
        // The card is free again, but the draw still counts against draws left.
        $this->assertSame(2, $inventory->envelopes()[$fifty->id]['on_hand']);
        $this->assertSame(1, $inventory->envelopes()[$fifty->id]['count']);

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

    public function test_admin_imports_team_members_from_a_csv(): void
    {
        $admin = User::factory()->role('admin')->create();
        User::factory()->role('supervisor')->create(['email' => 'boss@example.com']);
        $csv = "\xEF\xBB\xBFFirst Name,Last Name,Email\r\n"
            ."Amy,Zimmer,Amy@Example.com\r\n"
            ."\"Mary Jo\",O'Neil,maryjo@example.com\r\n"
            ."Boss,Person,boss@example.com\r\n"
            ."No,Email,\r\n"
            .",,\r\n"
            ."Amy,Again,amy@example.com\r\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('team.csv', $csv);

        $this->actingAs($admin)->post('/admin/users/import', ['file' => $file])
            ->assertSessionHas('status', 'Added 2 team member(s). Skipped 2 already on the roster. Skipped line(s) 5 with a missing name or email.');

        $this->assertSame('employee', User::where('email', 'amy@example.com')->value('role'));
        $this->assertSame("Mary Jo O'Neil", User::where('email', 'maryjo@example.com')->value('name'));
        $this->assertSame('supervisor', User::where('email', 'boss@example.com')->value('role'));
        $this->assertSame(4, User::count());

        $headerless = \Illuminate\Http\UploadedFile::fake()->createWithContent('team.csv', "Sam,Crew,sam@example.com\n");
        $this->actingAs($admin)->post('/admin/users/import', ['file' => $headerless])->assertSessionHas('status', 'Added 1 team member(s).');

        $this->actingAs(User::factory()->role('supervisor')->create())->post('/admin/users/import', ['file' => $headerless])->assertForbidden();
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
        $this->assertSame(1, $shears->fresh()->available);
        $this->assertSame('void', $draw->credit->fresh()->status);
    }

    public function test_admin_changes_a_won_prize_to_a_gift_card_brand_and_the_box_follows(): void
    {
        $admin = User::factory()->role('admin')->create();
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'value_cents' => 6000, 'quantity' => 1]);
        $draw = app(DrawMachine::class)->draw($this->credit(User::factory()->create()));
        $card = Prize::create(['name' => '$25 gift card', 'type' => 'gift_card', 'value_cents' => 2500, 'cash_bonus_allowed' => true, 'available' => 10]);
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
        $supervisor = User::factory()->role('admin')->create();
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
        $admin = User::factory()->role('admin')->create();
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'value_cents' => 6000, 'quantity' => 3]);
        $draw = app(DrawMachine::class)->draw($this->credit(User::factory()->create()));
        $card = Prize::create(['name' => '$10 gift card', 'type' => 'gift_card', 'value_cents' => 1000, 'available' => 4]);
        $wawa = GiftCardStock::create(['prize_id' => $card->id, 'brand' => 'Wawa', 'quantity' => 2]);
        $amazon = GiftCardStock::create(['prize_id' => $card->id, 'brand' => 'Amazon', 'quantity' => 2]);
        $inventory = app(Inventory::class);
        $this->assertSame(4, $inventory->envelopes()[$card->id]['count']);

        $this->actingAs($admin)->post("/supervisor/inventory/brands/{$wawa->id}/toggle");
        $this->assertSame(2, $inventory->envelopes()[$card->id]['count']);
        $this->actingAs($admin)->get('/supervisor/inventory')->assertSee('Hidden prizes and gift cards (1)');

        $this->actingAs($admin)->post("/supervisor/inventory/prizes/{$shears->id}/toggle");
        $this->assertArrayNotHasKey($shears->id, $inventory->envelopes()->all());
        $this->actingAs($admin)->post("/supervisor/inventory/prizes/{$shears->id}/toggle");
        $this->assertArrayHasKey($shears->id, $inventory->envelopes()->all());

        $this->actingAs($admin)->delete("/supervisor/inventory/brands/{$amazon->id}");
        $this->assertNull($amazon->fresh());
        $this->actingAs($admin)->delete("/supervisor/inventory/prizes/{$shears->id}");
        $this->assertSoftDeleted($shears);
        $this->actingAs($admin)->get('/supervisor/inventory')->assertDontSee('value="Raptor shears"', false);
        // The past draw still shows its prize.
        $this->actingAs($admin)->get('/supervisor/reports')->assertOk()->assertSee($draw->fresh()->prize_name);
    }

    public function test_lockers_are_laid_out_and_numbered_down_each_column(): void
    {
        $this->assertSame(72, Locker::where('office', 'main')->count());
        $this->assertSame(['row' => 0, 'col' => 0], Locker::where('number', 1)->first()->only('row', 'col'));
        $this->assertSame(['row' => 5, 'col' => 0], Locker::where('number', 6)->first()->only('row', 'col'));
        $this->assertSame(['row' => 0, 'col' => 1], Locker::where('number', 7)->first()->only('row', 'col'));
        $this->assertSame(['row' => 5, 'col' => 11], Locker::where('number', 72)->first()->only('row', 'col'));
        $this->assertSame([100, 101, 102, 103, 104], Locker::where('office', 'newport_news')->orderBy('row')->pluck('number')->all());
    }

    public function test_supervisor_dispenses_checked_prizes_directly(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $pat = User::factory()->create(['name' => 'Pat Medic']);
        Prize::create(['name' => 'Candy', 'type' => 'item', 'diminishing' => false, 'quantity' => 5]);
        $a = app(DrawMachine::class)->draw($this->credit($pat));
        $b = app(DrawMachine::class)->draw($this->credit($pat));
        $c = app(DrawMachine::class)->draw($this->credit($pat));

        $this->actingAs($supervisor)->get('/supervisor')->assertOk()->assertSee('Dispense checked');
        $this->actingAs($supervisor)->get("/supervisor/dispense/{$pat->id}?draws[]={$a->id}&draws[]={$b->id}")->assertOk()->assertSee('Newport News');

        $this->actingAs($supervisor)->post("/supervisor/dispense/{$pat->id}", ['draws' => [$a->id, $b->id], 'method' => 'direct'])
            ->assertRedirect('/supervisor');
        $this->assertSame('awarded', $a->fresh()->status);
        $this->assertSame('awarded', $b->fresh()->status);
        $this->assertSame('won', $c->fresh()->status);
        Notification::assertNotSentTo($pat, PrizesInLocker::class);
    }

    public function test_locker_dispense_pickup_and_supervisor_forfeit(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $pat = User::factory()->create(['name' => 'Pat Medic']);
        $other = User::factory()->create(['name' => 'Chris Other']);
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'value_cents' => 6000, 'quantity' => 2]);
        $first = app(DrawMachine::class)->draw($this->credit($pat));
        $locker = Locker::where('number', 23)->first();

        $this->actingAs($supervisor)->post("/supervisor/dispense/{$pat->id}", [
            'draws' => [$first->id], 'method' => 'locker', 'office' => 'main', 'locker_id' => $locker->id, 'combination' => ['12', '34', '5'],
        ])->assertRedirect('/supervisor');

        $assignment = LockerAssignment::first();
        $this->assertSame('12 34 5', $assignment->combination);
        $this->assertSame('in_locker', $first->fresh()->status);
        $this->assertNull($first->fresh()->awarded_at);
        Notification::assertSentTo($pat, PrizesInLocker::class);

        // The employee sees the locker, combination and days left; someone else can't pick it up.
        $this->actingAs($pat)->get('/')->assertSee('locker 23', false)->assertSee('Main')->assertSee('12 34 5')->assertSee('7 days')->assertSee('Picked Up');
        $this->actingAs($other)->post("/lockers/{$assignment->id}/picked-up")->assertForbidden();

        // The locker is taken until it's picked up.
        $second = app(DrawMachine::class)->draw($this->credit($other));
        $this->actingAs($supervisor)->post("/supervisor/dispense/{$other->id}", [
            'draws' => [$second->id], 'method' => 'locker', 'office' => 'main', 'locker_id' => $locker->id, 'combination' => [1, 2, 3],
        ])->assertSessionHas('error');
        $this->assertSame('won', $second->fresh()->status);

        // A Newport News locker can't be picked with the Main office chosen.
        $this->actingAs($supervisor)->post("/supervisor/dispense/{$other->id}", [
            'draws' => [$second->id], 'method' => 'locker', 'office' => 'main', 'locker_id' => Locker::where('number', 100)->value('id'), 'combination' => [1, 2, 3],
        ])->assertSessionHasErrors('locker_id');

        $this->actingAs($pat)->post("/lockers/{$assignment->id}/picked-up")->assertRedirect();
        $this->assertSame('awarded', $first->fresh()->status);
        $this->assertNotNull($first->fresh()->awarded_at);
        $this->assertSame($supervisor->id, $first->fresh()->awarded_by);
        $this->assertNull($locker->fresh()->current);

        // Past the pickup date nothing happens on its own; a supervisor checks the locker.
        app(Lockers::class)->dispense($other, [$second->id], $supervisor, $locker, '1 2 3');
        $this->assertSame(0, $shears->fresh()->quantity);
        $current = $locker->fresh()->current;
        $this->actingAs($supervisor)->post("/supervisor/lockers/{$current->id}/forfeit")->assertSessionHas('error');
        $this->travel(8)->days();
        $this->artisan('drawbox:expire')->assertSuccessful();
        $this->assertSame('in_locker', $second->fresh()->status);
        $this->assertTrue($current->fresh()->isOverdue());
        $this->actingAs($other)->get('/')->assertSee('has run out');

        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin)->get('/admin/lockers')->assertOk()->assertSee('Overdue')->assertSee('1 2 3');
        $this->actingAs($supervisor)->get('/supervisor')->assertSee('Still full, forfeit')->assertSee('Empty, picked up');

        // Still full: forfeited back into the box, and the locker is open again.
        $this->actingAs($supervisor)->post("/supervisor/lockers/{$current->id}/forfeit")->assertRedirect();
        $this->assertSame('forfeited', $second->fresh()->status);
        $this->assertNull($second->fresh()->awarded_at);
        $this->assertSame(1, $shears->fresh()->quantity);
        $this->assertNull($locker->fresh()->current);
        $this->actingAs($admin)->get('/admin/lockers')->assertSee('Last: '.$other->name, false);
    }

    public function test_overdue_locker_found_empty_counts_as_picked_up(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $pat = User::factory()->create();
        $shears = Prize::create(['name' => 'Raptor shears', 'type' => 'item', 'value_cents' => 6000, 'quantity' => 1]);
        $draw = app(DrawMachine::class)->draw($this->credit($pat));
        $assignment = app(Lockers::class)->dispense($pat, [$draw->id], $supervisor, Locker::where('number', 100)->first(), '4 5 6');

        $this->travel(10)->days();
        $this->actingAs($supervisor)->post("/supervisor/lockers/{$assignment->id}/picked-up")->assertRedirect();
        $this->assertSame('awarded', $draw->fresh()->status);
        $this->assertSame(0, $shears->fresh()->quantity);
        $this->assertNull(Locker::where('number', 100)->first()->current);
    }

    public function test_pto_and_cash_bonus_cannot_go_in_a_locker(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $pat = User::factory()->create();
        Prize::create(['name' => 'PTO (2 hours)', 'type' => 'pto', 'pto_hours' => 2, 'value_cents' => 5000, 'quantity' => 1]);
        $pto = app(DrawMachine::class)->draw($this->credit($pat));

        $this->actingAs($supervisor)->get('/supervisor')->assertSee('Mark PTO given');
        $this->actingAs($supervisor)->post("/supervisor/dispense/{$pat->id}", [
            'draws' => [$pto->id], 'method' => 'locker', 'office' => 'main', 'locker_id' => Locker::where('number', 1)->value('id'), 'combination' => [1, 2, 3],
        ])->assertSessionHas('error');
        $this->assertSame('won', $pto->fresh()->status);
    }

    public function test_inactive_team_members_are_listed_below_the_roster(): void
    {
        $admin = User::factory()->role('admin')->create(['name' => 'Mia Middle']);
        User::factory()->create(['name' => 'Zoe Adams', 'active' => false]);
        User::factory()->create(['name' => 'Amy Zimmer']);

        $this->actingAs($admin)->get('/admin/users')
            ->assertSee('Roster (2 active)')
            ->assertSeeInOrder(['Mia Middle', 'Amy Zimmer', 'Inactive team members (1)', 'Zoe Adams']);
    }

    public function test_pages_render(): void
    {
        $this->seed();
        $admin = User::factory()->role('admin')->create();
        $credit = $this->credit($admin);
        Prize::where('name', 'Candy')->update(['quantity' => 5]);
        $draw = app(DrawMachine::class)->draw($credit);

        foreach (['/', '/dispatch', '/supervisor', '/supervisor/reports', '/supervisor/inventory', '/supervisor/inventory/log', '/supervisor/settings', '/admin/users', '/admin/lockers', '/supervisor/reports/won', "/admin/draws/{$draw->id}/edit", "/draws/{$draw->id}?reveal=1"] as $page) {
            $this->actingAs($admin)->get($page)->assertOk();
        }
        $this->get('/login')->assertRedirect('/');
        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('Send my code');
        $this->get('/')->assertRedirect('/login');
    }

    public function test_sign_ins_and_failed_sign_ins_are_audited(): void
    {
        $user = User::factory()->create(['email' => 'medic@example.com']);

        $this->post('/login', ['email' => 'nobody@example.com']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login.unknown_email', 'email' => 'nobody@example.com']);

        $this->post('/login', ['email' => $user->email]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login.code_sent', 'user_id' => $user->id]);
        LoginCode::first()->update(['code_hash' => bcrypt('123456')]);

        $this->post('/login/code', ['code' => '654321']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login.failed', 'user_id' => $user->id]);
        $this->assertNull($user->fresh()->last_login_at);

        $this->post('/login/code', ['code' => '123456'])->assertRedirect('/');
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'user_id' => $user->id]);
        $this->assertNotNull($user->fresh()->last_login_at);

        // The code itself never lands in the log.
        $this->assertSame(0, \App\Models\AuditLog::where('description', 'like', '%123456%')->count());

        $this->post('/logout');
        $this->assertDatabaseHas('audit_logs', ['action' => 'logout', 'user_id' => $user->id]);
    }

    public function test_actions_are_audited_and_only_admins_see_the_log(): void
    {
        Prize::create(['name' => 'Stethoscope', 'type' => 'item', 'value_cents' => 12000, 'quantity' => 1]);
        $supervisor = User::factory()->role('supervisor')->create();
        $admin = User::factory()->role('admin')->create();
        $crew = User::factory()->create(['name' => 'Pat Medic']);

        $credit = $this->credit($crew);
        $this->actingAs($crew)->post("/credits/{$credit->id}/draw");
        $this->assertDatabaseHas('audit_logs', ['action' => 'draws.store', 'user_id' => $crew->id, 'description' => 'Drew Stethoscope ($120) from the call at Sentara Leigh. Changed Stethoscope: draws left from 1 to 0, on hand from 1 to 0.']);

        $draw = Draw::first();
        $this->actingAs($supervisor)->post("/supervisor/draws/{$draw->id}/award");
        $this->assertDatabaseHas('audit_logs', ['action' => 'supervisor.award', 'user_id' => $supervisor->id]);

        $this->actingAs($supervisor)->get('/admin/audit')->assertForbidden();
        $this->actingAs($admin)->get('/admin/audit')->assertOk()->assertSee('Drew Stethoscope')->assertSee('Marked a prize received');
        $this->actingAs($admin)->get('/admin/audit?user='.$supervisor->id)->assertOk()
            ->assertSee('Marked a prize received')->assertDontSee('Drew Stethoscope');

        $this->actingAs($admin)->get('/admin/users')->assertSee('Last login: never');
    }

    public function test_audit_log_says_what_changed_from_and_to(): void
    {
        $admin = User::factory()->role('admin')->create();
        $card = Prize::create(['name' => '$5 gift card', 'type' => 'gift_card', 'value_cents' => 500, 'available' => 12, 'monthly_quantity' => 12]);
        $wawa = GiftCardStock::create(['prize_id' => $card->id, 'brand' => 'Wawa', 'quantity' => 4]);
        $medic = User::factory()->create(['name' => 'Pat Medic']);

        $this->actingAs($admin)->put("/supervisor/inventory/prizes/{$card->id}", [
            'name' => '$5 gift card', 'type' => 'gift_card', 'value' => 5, 'cash_bonus_allowed' => '0', 'taxable' => '1', 'available' => 8, 'monthly_quantity' => 10,
        ]);
        $this->actingAs($admin)->put("/supervisor/inventory/brands/{$wawa->id}", ['quantity' => 6]);
        $this->actingAs($admin)->put("/admin/users/{$medic->id}", ['name' => 'Pat Medic', 'email' => $medic->email, 'role' => 'dispatcher', 'active' => '1']);
        $this->actingAs($admin)->put('/supervisor/settings', ['draw_window_days' => 10, 'locker_pickup_days' => 7, 'expiry_reminder_enabled' => '1']);

        $logs = \App\Models\AuditLog::orderBy('id')->pluck('description');
        $this->assertSame('Saved $5 gift card. Changed $5 gift card: monthly from 12 to 10, draws left from 12 to 8.', $logs[0]);
        $this->assertStringContainsString('$5 gift card Wawa: on hand from 4 to 6.', $logs[1]);
        $this->assertStringContainsString('Pat Medic: role from employee to dispatcher.', $logs[2]);
        $this->assertStringContainsString('Settings: Draw window (days) from 7 to 10.', $logs[3]);
        $this->assertStringNotContainsString('Locker pickup', $logs[3]);
    }
}
