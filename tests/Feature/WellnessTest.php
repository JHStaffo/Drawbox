<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PermissionProfile;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\LoginCode as LoginCodeNotification;
use App\Wellness\Models\Participant;
use App\Wellness\Models\Point;
use App\Wellness\Models\Reading;
use App\Wellness\Models\Rule;
use App\Wellness\Wellness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WellnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday, December 9, 2026. That week runs Sunday Dec 6 to Saturday Dec 12.
        Carbon::setTestNow('2026-12-09 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A Team Member whose profile includes Wellness. */
    private function member(array $attributes = [], bool $join = true): User
    {
        $profile = PermissionProfile::where('name', 'Team Member')->first();
        if (! in_array('wellness', $profile->permissions, true)) {
            $profile->update(['permissions' => [...$profile->permissions, 'wellness']]);
        }
        $user = User::factory()->role('employee')->create($attributes);
        if ($join) {
            Participant::create(['user_id' => $user->id, 'joined_at' => now(), 'consent_version' => '2026-11-01', 'consent_at' => now()]);
        }

        return $user;
    }

    private function perfectDay(): array
    {
        return ['meals' => 3, 'water_oz' => 64, 'meal_plan' => 1, 'steps' => 10000, 'sleep_hours' => 6, 'exercise_minutes' => 30];
    }

    public function test_wellness_is_off_for_everyone_until_a_profile_gets_it(): void
    {
        $admin = PermissionProfile::where('name', 'Admin')->first();
        $this->assertTrue($admin->allows('wellness_admin'));
        $this->assertTrue($admin->allows('wellness_settings'));
        $this->assertFalse($admin->allows('wellness'));
        $this->assertFalse(PermissionProfile::where('name', 'Supervisor')->first()->allows('wellness_admin'));

        $this->actingAs(User::factory()->role('employee')->create())->get('/wellness')->assertForbidden();
        $this->actingAs(User::factory()->role('employee')->create())->get('/')->assertDontSee('My Wellness');
        $this->actingAs(User::factory()->role('admin')->create())->get('/wellness/admin/participants')->assertOk()->assertSee('Nobody has joined yet');
    }

    public function test_joining_needs_consent_and_is_audited(): void
    {
        $user = $this->member(['name' => 'Jamie Medic'], join: false);

        $this->actingAs($user)->get('/wellness')->assertOk()->assertSee('Wellness program agreement')->assertSee('I agree');
        $this->actingAs($user)->post('/wellness/join')->assertSessionHasErrors('consent');
        $this->assertDatabaseCount('wellness_participants', 0);

        $this->actingAs($user)->post('/wellness/join', ['consent' => '1'])->assertRedirect('/wellness');
        $participant = Participant::first();
        $this->assertSame('2026-11-01', $participant->consent_version);
        $log = AuditLog::where('action', 'wellness.join')->latest('id')->first();
        $this->assertStringContainsString('Jamie Medic joined the wellness program and agreed to the rules dated 2026-11-01', $log->description);
        $this->actingAs($user)->get('/wellness')->assertOk()->assertSee('My Wellness')->assertSee('Log today');
    }

    public function test_nothing_else_opens_until_they_agree(): void
    {
        $user = $this->member(join: false);

        foreach (['/wellness/log', '/wellness/leaderboard', '/wellness/connect'] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect('/wellness');
        }
        $this->actingAs($user)->put('/wellness/log/2026-12-09', ['water_oz' => 64])->assertRedirect('/wellness');
        $this->assertDatabaseCount('wellness_readings', 0);
        $this->actingAs($user)->get('/wellness/rules')->assertOk();
        $this->actingAs($user)->get('/')->assertSee('Sign up')->assertDontSee('Log a day');

        $this->actingAs($user)->post('/wellness/join', ['consent' => '1']);
        $this->actingAs($user)->get('/wellness/log')->assertOk();
        $this->actingAs($user)->get('/')->assertSee('Log a day')->assertDontSee('>Sign up<', false);
    }

    public function test_admins_can_turn_wellness_on_for_one_team_member(): void
    {
        $admin = User::factory()->role('admin')->create();
        $tester = User::factory()->role('employee')->create(['name' => 'Tess Tester']);
        $other = User::factory()->role('employee')->create();

        $this->actingAs($tester)->get('/wellness')->assertForbidden();
        $this->actingAs($admin)->post('/wellness/admin/testers', ['user_id' => $tester->id])->assertSessionHas('status', 'Tess Tester can now sign up for Wellness.');
        $this->assertSame('Gave Tess Tester Wellness individually for testing.', AuditLog::where('action', 'wellness.admin.testers.store')->latest('id')->first()->description);
        $this->actingAs($admin)->get('/wellness/admin/participants')->assertSee('Tess Tester');

        $this->actingAs($tester)->get('/wellness')->assertOk()->assertSee('I agree');
        $this->actingAs($tester)->post('/wellness/join', ['consent' => '1']);
        $this->actingAs($tester)->put('/wellness/log/2026-12-09', ['water_oz' => 64])->assertSessionHasNoErrors();
        $this->actingAs($other)->get('/wellness')->assertForbidden();
        Sanctum::actingAs($tester, ['wellness']);
        $this->getJson('/api/v1/me')->assertJsonPath('user.wellness.can_join', true);
        $this->getJson('/api/v1/wellness/summary')->assertOk();

        $testerRow = \App\Wellness\Models\Tester::first();
        $this->actingAs($admin)->delete("/wellness/admin/testers/{$testerRow->id}")->assertSessionHasNoErrors();
        $this->assertStringContainsString('Took individual Wellness access away from Tess Tester.', AuditLog::where('action', 'wellness.admin.testers.destroy')->latest('id')->first()->description);
        $this->actingAs($tester->fresh())->get('/wellness')->assertForbidden();
        $this->assertSame(1, (int) Point::where('user_id', $tester->id)->sum('points'));

        // A supervisor without Wellness admin can't add testers.
        $this->actingAs(User::factory()->role('supervisor')->create())->post('/wellness/admin/testers', ['user_id' => $other->id])->assertForbidden();
    }

    public function test_a_perfect_day_scores_eight_and_the_day_never_goes_over(): void
    {
        $user = $this->member();

        $this->actingAs($user)->put('/wellness/log/2026-12-09', $this->perfectDay())
            ->assertSessionHas('status', 'Saved Wednesday, Dec 9. You have 8 of 8 daily points.');
        $this->assertSame(8, (int) Point::where('user_id', $user->id)->sum('points'));
        $this->assertSame(360.0, Reading::where('metric', 'sleep_minutes')->first()->value);
        $log = AuditLog::where('action', 'wellness.log.save')->latest('id')->first();
        $this->assertStringContainsString('Steps from none to 10,000', $log->description);
        $this->assertStringContainsString('Sleep from none to 6 h', $log->description);

        // Even if a rule is changed to give more, the day stays capped at 8.
        Rule::where('key', 'meals')->update(['max_per_day' => 5]);
        $this->actingAs($user)->put('/wellness/log/2026-12-09', ['meals' => 5] + $this->perfectDay());
        $this->assertSame(8, (int) Point::where('user_id', $user->id)->sum('points'));

        // Partial day: 2 meals and short sleep.
        $this->actingAs($user)->put('/wellness/log/2026-12-08', ['meals' => 2, 'sleep_hours' => 5.5, 'water_oz' => 70]);
        $this->assertSame(3, (int) Point::where('user_id', $user->id)->where('day', '2026-12-08')->sum('points'));
    }

    public function test_the_same_metric_from_two_sources_counts_once(): void
    {
        $user = $this->member();
        $wellness = app(Wellness::class);

        $wellness->record($user, '2026-12-09', ['steps' => 6000], 'manual');
        $wellness->record($user, '2026-12-09', ['steps' => 11000], 'apple_health', 'Apple Watch');
        $this->assertSame(1, (int) Point::where('user_id', $user->id)->sum('points'));
        $this->assertSame(2, Reading::count());

        // A later sync from the same source replaces its value rather than adding a row.
        $wellness->record($user, '2026-12-09', ['steps' => 12500], 'apple_health', 'Apple Watch');
        $this->assertSame(2, Reading::count());
        $this->assertSame(1, (int) Point::where('user_id', $user->id)->sum('points'));
    }

    public function test_device_data_can_be_typed_only_while_allowed_or_with_an_exception(): void
    {
        Setting::put('wellness_manual_device', '0');
        $user = $this->member();

        $this->actingAs($user)->put('/wellness/log/2026-12-09', ['steps' => 12000])->assertSessionHasErrors('steps');
        $this->actingAs($user)->get('/wellness/log')->assertDontSee('name="steps"', false);
        $this->actingAs($user)->put('/wellness/log/2026-12-09', ['water_oz' => 80])->assertSessionHasNoErrors();

        $admin = User::factory()->role('admin')->create();
        $participant = Participant::where('user_id', $user->id)->first();
        $this->actingAs($admin)->put("/wellness/admin/participants/{$participant->id}/exception", ['manual_device_until' => '2026-12-20'])
            ->assertSessionHasErrors('exception_note');
        $this->actingAs($admin)->put("/wellness/admin/participants/{$participant->id}/exception", ['manual_device_until' => '2026-12-20', 'exception_note' => 'Watch in for repair'])
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('typing in device data allowed until from none to Dec 20, 2026', AuditLog::where('action', 'wellness.admin.exception')->latest('id')->first()->description);

        $this->actingAs($user)->put('/wellness/log/2026-12-09', ['steps' => 12000])->assertSessionHasNoErrors();
        $this->assertSame(2, (int) Point::where('user_id', $user->id)->sum('points'));
    }

    public function test_weeks_run_sunday_to_saturday_and_close_after_the_grace_period(): void
    {
        $user = $this->member();
        $wellness = app(Wellness::class);

        // Saturday Dec 5 is last week; it stays open until Tuesday Dec 8 (3 days), so on Dec 9 it's closed.
        $this->actingAs($user)->put('/wellness/log/2026-12-05', ['water_oz' => 64])->assertSessionHasErrors('day');
        // Sunday Dec 6 is this week.
        $this->actingAs($user)->put('/wellness/log/2026-12-06', ['water_oz' => 64])->assertSessionHasNoErrors();
        // Tomorrow can't be logged.
        $this->actingAs($user)->put('/wellness/log/2026-12-10', ['water_oz' => 64])->assertSessionHasErrors('day');

        foreach (['2026-12-06', '2026-12-07', '2026-12-08', '2026-12-09'] as $day) {
            $wellness->record($user, $day, ['meals' => 3, 'water_oz' => 64, 'meal_plan' => 1, 'steps' => 10000, 'sleep_minutes' => 400, 'exercise_minutes' => 45], 'manual');
        }
        $week = app(\App\Wellness\Progress::class)->week($user);
        $this->assertSame('2026-12-06', $week['from']);
        $this->assertSame('2026-12-12', $week['to']);
        $this->assertSame(32, $week['points']);
        $this->assertSame(56, $week['target']);
        $this->assertFalse($week['qualified']);
        $this->assertSame(4, app(\App\Wellness\Progress::class)->streak($user));

        // Once the week closes, a late change does nothing to its points.
        Carbon::setTestNow('2026-12-16 09:00:00');
        $this->assertTrue(\App\Wellness\Calendar::isLocked('2026-12-09'));
        Reading::where('day', '2026-12-09')->where('metric', 'steps')->update(['value' => 0]);
        $this->assertSame(8, app(\App\Wellness\Scoring::class)->scoreDay($user, '2026-12-09'));
    }

    public function test_a_rule_change_starts_on_its_date_and_leaves_earlier_days_alone(): void
    {
        $user = $this->member();
        $admin = User::factory()->role('admin')->create();
        $wellness = app(Wellness::class);
        $steps = Rule::where('key', 'steps')->first();

        $this->actingAs($admin)->post('/wellness/admin/rules/steps', ['effective_from' => '2026-12-08', 'name' => 'Steps', 'points' => 1, 'threshold' => 8000, 'max_per_day' => 1])
            ->assertSessionHasErrors('effective_from');
        $this->actingAs($admin)->post('/wellness/admin/rules/steps', ['effective_from' => '2026-12-10', 'name' => 'Steps', 'points' => 2, 'threshold' => 8000, 'max_per_day' => 2])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Rule::where('key', 'steps')->count());
        $this->assertSame(10000.0, $steps->fresh()->threshold);
        $log = AuditLog::where('action', 'wellness.admin.rules.store')->latest('id')->first();
        $this->assertStringContainsString('starting Dec 10, 2026: points from 1 to 2, goal from 10000 to 8000', $log->description);

        $wellness->record($user, '2026-12-09', ['steps' => 9000], 'manual');
        $this->assertSame(0, (int) Point::where('day', '2026-12-09')->sum('points'));

        Carbon::setTestNow('2026-12-10 12:00:00');
        $wellness->record($user, '2026-12-10', ['steps' => 9000], 'manual');
        $this->assertSame(2, (int) Point::where('day', '2026-12-10')->sum('points'));
    }

    public function test_the_leaderboard_shows_names_and_points_only(): void
    {
        $a = $this->member(['name' => 'Alex Able']);
        $b = $this->member(['name' => 'Bea Brave']);
        $this->member(['name' => 'Cam Calm']);
        app(Wellness::class)->record($a, '2026-12-09', ['meals' => 2, 'sleep_minutes' => 480], 'manual');
        app(Wellness::class)->record($b, '2026-12-09', ['meals' => 3, 'sleep_minutes' => 300], 'manual');

        $this->actingAs($a)->get('/wellness/leaderboard')->assertOk()->assertSeeInOrder(['Alex Able', 'Bea Brave', 'Cam Calm'])->assertDontSee('480');

        Sanctum::actingAs($a, ['wellness']);
        $json = $this->getJson('/api/v1/wellness/leaderboard?period=month')->assertOk()->json();
        $this->assertSame([1, 1, 3], array_column($json['rows'], 'rank'));
        $this->assertSame(['rank', 'user_id', 'name', 'points'], array_keys($json['rows'][0]));
        $this->assertTrue($json['test_period']);
    }

    public function test_an_app_signs_in_with_an_emailed_code_and_logs_a_day(): void
    {
        Notification::fake();
        $user = $this->member(['email' => 'medic@example.com']);

        $this->postJson('/api/v1/auth/code', ['email' => 'nobody@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/code', ['email' => 'medic@example.com'])->assertOk();
        $code = null;
        Notification::assertSentTo($user, LoginCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $this->postJson('/api/v1/auth/token', ['email' => 'medic@example.com', 'code' => '000000', 'device_name' => 'iPhone'])->assertStatus(422);
        $token = $this->postJson('/api/v1/auth/token', ['email' => 'medic@example.com', 'code' => $code, 'device_name' => 'iPhone'])
            ->assertCreated()->assertJsonPath('user.wellness.joined', true)->json('token');
        $this->assertSame('Signed in on the app (iPhone).', AuditLog::where('action', 'api.auth.token')->first()->description);

        $headers = ['Authorization' => "Bearer {$token}"];
        $this->putJson('/api/v1/wellness/days/2026-12-09', ['meals' => 3, 'water_oz' => 64], $headers)
            ->assertOk()->assertJsonPath('points', 4);
        $this->assertStringContainsString('Logged wellness on the app for Wednesday, Dec 9', AuditLog::where('action', 'api.wellness.days.update')->first()->description);
        $this->getJson('/api/v1/wellness/summary', $headers)->assertOk()
            ->assertJsonPath('today.points', 4)->assertJsonPath('week.target', 56)->assertJsonPath('streak', 1);
        $this->getJson('/api/v1/wellness/rules', $headers)->assertOk()->assertJsonPath('daily_max', 8)->assertJsonPath('week_starts', 'sunday');

        $this->deleteJson('/api/v1/auth/token', [], $headers)->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_sync_key_can_only_send_readings(): void
    {
        $user = $this->member();
        $this->actingAs($user)->post('/wellness/connect/keys', ['name' => 'My iPhone'])->assertSessionHas('new_token');
        $key = session('new_token');
        $this->assertSame('Made a wellness sync key named "My iPhone".', AuditLog::where('action', 'wellness.connect.store')->first()->description);
        auth()->forgetGuards();

        $headers = ['Authorization' => "Bearer {$key}"];
        $this->getJson('/api/v1/wellness/summary', $headers)->assertForbidden();
        $this->postJson('/api/v1/wellness/readings', [
            'source' => 'apple_health',
            'device' => 'Apple Watch Series 10',
            'readings' => [
                ['day' => '2026-12-09', 'metric' => 'steps', 'value' => 10422],
                ['day' => '2026-12-09', 'metric' => 'sleep_minutes', 'value' => 415],
                ['day' => '2026-12-09', 'metric' => 'exercise_minutes', 'value' => 34],
                ['day' => '2026-12-08', 'metric' => 'steps', 'value' => 4000],
                ['day' => '2026-12-01', 'metric' => 'steps', 'value' => 9000],
                ['day' => '2026-12-10', 'metric' => 'steps', 'value' => 9000],
            ],
        ], $headers)->assertOk()
            ->assertJsonPath('accepted.0.day', '2026-12-09')->assertJsonPath('accepted.0.points', 3)
            ->assertJsonPath('skipped.0.reason', 'closed')->assertJsonPath('skipped.1.reason', 'future');
        $this->assertSame('Apple Watch Series 10', Reading::where('metric', 'steps')->where('day', '2026-12-09')->first()->device);
        $this->assertStringContainsString('Apple Health sync: 2 day(s) saved, 2 skipped.', AuditLog::where('action', 'api.wellness.readings')->first()->description);

        // Meals can't come from a device sync.
        $this->postJson('/api/v1/wellness/readings', ['source' => 'apple_health', 'readings' => [['day' => '2026-12-09', 'metric' => 'meals', 'value' => 3]]], $headers)
            ->assertStatus(422);

        $this->actingAs($user)->get('/wellness')->assertSee('Apple Health: last synced');
        $tokenId = $user->tokens()->first()->id;
        $this->actingAs($user)->delete("/wellness/connect/keys/{$tokenId}")->assertSessionHas('status', 'Removed "My iPhone".');
        auth()->forgetGuards();
        $this->postJson('/api/v1/wellness/readings', ['source' => 'apple_health', 'readings' => [['day' => '2026-12-09', 'metric' => 'steps', 'value' => 1]]], $headers)
            ->assertUnauthorized();
    }

    public function test_turning_the_module_off_hides_it_everywhere_but_its_settings(): void
    {
        $user = $this->member();
        $admin = User::factory()->role('admin')->create();

        $this->actingAs($admin)->put('/wellness/admin/settings', ['wellness_enabled' => '0', 'wellness_official_start' => '2027-01-01', 'wellness_grace_days' => 3])
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('Wellness module from on to off', AuditLog::where('action', 'wellness.admin.settings.update')->first()->description);

        $this->actingAs($user)->get('/wellness')->assertNotFound();
        $this->actingAs($user)->get('/')->assertOk()->assertDontSee('My Wellness');
        $this->actingAs($admin)->get('/wellness/admin/participants')->assertNotFound();
        $this->actingAs($admin)->get('/wellness/admin/settings')->assertOk();
        Sanctum::actingAs($user, ['wellness']);
        $this->getJson('/api/v1/wellness/summary')->assertNotFound();
        // The rest of Pulse is unaffected.
        $this->actingAs($admin)->get('/supervisor')->assertOk();
    }

    public function test_api_answers_clearly_before_joining(): void
    {
        $user = $this->member(join: false);
        Sanctum::actingAs($user, ['wellness']);

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.wellness.can_join', true)->assertJsonPath('user.wellness.joined', false);
        $this->getJson('/api/v1/wellness/summary')->assertStatus(409)->assertJsonPath('code', 'not_joined');
        $this->postJson('/api/v1/wellness/join', ['consent' => true])->assertOk()->assertJsonPath('user.wellness.joined', true);
        $this->getJson('/api/v1/wellness/summary')->assertOk();

        $other = User::factory()->role('employee')->create();
        PermissionProfile::where('name', 'Team Member')->update(['permissions' => json_encode([])]);
        Sanctum::actingAs($other->fresh(), ['wellness']);
        $this->getJson('/api/v1/wellness/summary')->assertForbidden();
    }

    public function test_every_wellness_page_opens(): void
    {
        $user = $this->member();
        app(Wellness::class)->record($user, '2026-12-09', ['meals' => 1, 'steps' => 12000], 'manual');
        app(Wellness::class)->record($user, '2026-12-09', ['steps' => 12000], 'apple_health', 'Apple Watch');
        $admin = User::factory()->role('admin')->create();

        foreach (['/wellness', '/wellness/log', '/wellness/log/2026-12-07', '/wellness/log/2026-11-30', '/wellness/leaderboard?period=year', '/wellness/rules', '/wellness/connect'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
        $this->actingAs($user)->get('/wellness/log/not-a-day')->assertNotFound();
        foreach (['/wellness/admin/participants', '/wellness/admin/rules', '/wellness/admin/settings'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        $this->actingAs($user)->get('/wellness/admin/rules')->assertForbidden();
    }
}
