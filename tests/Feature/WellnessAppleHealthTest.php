<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PermissionProfile;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\WellnessSyncStopped;
use App\Wellness\Http\Controllers\Api\AppleHealthController;
use App\Wellness\Http\Controllers\ConnectController;
use App\Wellness\Models\Participant;
use App\Wellness\Models\Reading;
use App\Wellness\Wellness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WellnessAppleHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday, December 9, 2026, 9 PM, when the nightly automation runs.
        Carbon::setTestNow('2026-12-09 21:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(bool $join = true): User
    {
        $profile = PermissionProfile::where('name', 'Team Member')->first();
        if (! in_array('wellness', $profile->permissions, true)) {
            $profile->update(['permissions' => [...$profile->permissions, 'wellness']]);
        }
        $user = User::factory()->role('employee')->create();
        if ($join) {
            Participant::create(['user_id' => $user->id, 'joined_at' => now(), 'consent_version' => '2026-11-01', 'consent_at' => now()]);
        }

        return $user;
    }

    private function key(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('Apple Health Shortcut', [ConnectController::IMPORT_ABILITY])->plainTextToken];
    }

    private function value(User $user, string $day, string $metric): ?float
    {
        return Reading::where('user_id', $user->id)->where('day', $day)->where('metric', $metric)->where('source', 'apple_health')->value('value');
    }

    public function test_numbers_are_read_the_way_a_shortcut_writes_them(): void
    {
        $this->assertSame(8432.0, AppleHealthController::number('8,432'));
        $this->assertSame(8432.0, AppleHealthController::number('8432 steps'));
        $this->assertSame(42.5, AppleHealthController::number('42.5 min'));
        $this->assertSame(12.0, AppleHealthController::number(12));
        $this->assertNull(AppleHealthController::number(''));
        $this->assertNull(AppleHealthController::number('none'));
        $this->assertSame(450.0, AppleHealthController::hours('7.5'));
        $this->assertSame(450.0, AppleHealthController::hours('7.5 hr'));
        $this->assertSame(450.0, AppleHealthController::hours('7:30'));
        $this->assertSame(450.0, AppleHealthController::hours('7 hr 30 min'));
        $this->assertNull(AppleHealthController::hours(null));
    }

    public function test_the_shortcut_sends_a_day_and_gets_a_message_back(): void
    {
        $user = $this->member();
        $this->postJson('/api/v1/wellness/apple-health', [
            'day' => '2026-12-09', 'steps' => '10,422', 'sleep_hours' => '6.9 hr', 'exercise_minutes' => '34', 'water_oz' => '',
        ], $this->key($user))->assertOk()
            ->assertJsonPath('message', 'Pulse: Today 10,422 steps, 6.9 h sleep, 34 min exercise. 3 of 8 points so far.')
            ->assertJsonPath('days.0.saved', true);

        $this->assertSame(10422.0, $this->value($user, '2026-12-09', 'steps'));
        $this->assertSame(414.0, $this->value($user, '2026-12-09', 'sleep_minutes'));
        $this->assertNull($this->value($user, '2026-12-09', 'water_oz'));
        $this->assertSame('iPhone', Reading::where('user_id', $user->id)->value('device'));
        $this->assertStringContainsString('Apple Health Shortcut sync: 2026-12-09 Steps from none to 10,422', AuditLog::where('action', 'api.wellness.apple-health')->first()->description);
    }

    public function test_yesterday_is_sent_again_and_zeros_never_wipe_out_data(): void
    {
        $user = $this->member();
        $headers = $this->key($user);
        app(Wellness::class)->record($user, '2026-12-08', ['steps' => 9000, 'sleep_minutes' => 400], 'apple_health', 'iPhone');

        // The Shortcut's loop: yesterday first, then today, each with its own day.
        $this->postJson('/api/v1/wellness/apple-health', ['day' => '2026-12-08', 'steps' => '11,020', 'sleep_hours' => '0'], $headers)->assertOk();
        $this->postJson('/api/v1/wellness/apple-health', ['day' => 'today', 'steps' => 3000], $headers)->assertOk();
        $this->assertSame(11020.0, $this->value($user, '2026-12-08', 'steps'));
        $this->assertSame(400.0, $this->value($user, '2026-12-08', 'sleep_minutes'));
        $this->assertSame(3000.0, $this->value($user, '2026-12-09', 'steps'));

        // Or both days in one call with the yesterday_ prefix.
        $this->postJson('/api/v1/wellness/apple-health', ['steps' => 12000, 'yesterday_steps' => 11500, 'yesterday_exercise_minutes' => 31], $headers)
            ->assertOk()->assertJsonPath('days.0.day', '2026-12-08')->assertJsonPath('days.1.day', '2026-12-09');
        $this->assertSame(11500.0, $this->value($user, '2026-12-08', 'steps'));
        $this->assertSame(31.0, $this->value($user, '2026-12-08', 'exercise_minutes'));
        $this->assertSame(12000.0, $this->value($user, '2026-12-09', 'steps'));

        $this->postJson('/api/v1/wellness/apple-health', ['steps' => 0, 'sleep_hours' => ''], $headers)
            ->assertOk()->assertJsonPath('message', 'Pulse: nothing to send. Apple Health had no steps, sleep or exercise for that day yet.');
        $this->assertSame(12000.0, $this->value($user, '2026-12-09', 'steps'));
    }

    public function test_closed_and_future_days_are_explained(): void
    {
        $user = $this->member();
        $headers = $this->key($user);
        $this->postJson('/api/v1/wellness/apple-health', ['day' => '2026-12-01', 'steps' => 9000], $headers)
            ->assertOk()->assertJsonPath('message', "Pulse: 2026-12-01 wasn't saved because that week is closed.");
        $this->postJson('/api/v1/wellness/apple-health', ['day' => '2026-12-10', 'steps' => 9000], $headers)
            ->assertOk()->assertJsonPath('message', "Pulse: 2026-12-10 wasn't saved because that day hasn't happened yet.");
        $this->postJson('/api/v1/wellness/apple-health', ['day' => 'someday', 'steps' => 9000], $headers)->assertStatus(422);
        $this->assertSame(0, Reading::count());
    }

    public function test_the_shortcut_needs_a_good_key_and_a_signed_up_team_member(): void
    {
        $this->postJson('/api/v1/wellness/apple-health', ['steps' => 9000])->assertUnauthorized();

        $user = $this->member(join: false);
        $this->postJson('/api/v1/wellness/apple-health', ['steps' => 9000], $this->key($user))
            ->assertStatus(409)->assertJsonPath('message', 'Sign up for Wellness and agree to the rules first.');

        // A token without the wellness or import ability can't send.
        auth()->forgetGuards();
        $other = $this->member();
        $token = $other->createToken('Something else', ['other'])->plainTextToken;
        $this->postJson('/api/v1/wellness/apple-health', ['steps' => 9000], ['Authorization' => "Bearer {$token}"])->assertForbidden();
    }

    public function test_team_members_get_one_email_when_their_device_stops_syncing(): void
    {
        Notification::fake();
        $quiet = $this->member();
        $fine = $this->member();
        $never = $this->member();
        $excused = $this->member();
        Participant::where('user_id', $excused->id)->update(['manual_device_until' => '2026-12-31']);

        Carbon::setTestNow('2026-12-06 20:00:00');
        foreach ([$quiet, $excused] as $u) {
            app(Wellness::class)->record($u, '2026-12-06', ['steps' => 9000], 'apple_health');
        }
        Carbon::setTestNow('2026-12-09 08:00:00');
        app(Wellness::class)->record($fine, '2026-12-08', ['steps' => 9000], 'apple_health');
        app(Wellness::class)->record($never, '2026-12-08', ['steps' => 9000], 'manual');

        $this->artisan('wellness:sync-check')->expectsOutput('Sent 1 sync reminder(s).')->assertSuccessful();
        Notification::assertSentTo($quiet, WellnessSyncStopped::class, fn ($n) => $n->source === 'apple_health');
        Notification::assertSentTimes(WellnessSyncStopped::class, 1);
        $this->assertStringContainsString('Emailed 1 Team Member(s)', AuditLog::where('action', 'system.wellness-sync-check')->first()->description);

        // Not again for the same gap.
        Carbon::setTestNow('2026-12-10 08:00:00');
        $this->artisan('wellness:sync-check')->expectsOutput('Sent 0 sync reminder(s).');

        // A sync comes in, then stops again: a new email.
        app(Wellness::class)->record($quiet, '2026-12-10', ['steps' => 9000], 'apple_health');
        Carbon::setTestNow('2026-12-13 08:00:00');
        $this->artisan('wellness:sync-check')->expectsOutput('Sent 2 sync reminder(s).');
        Notification::assertSentToTimes($quiet, WellnessSyncStopped::class, 2);

        $mail = (new WellnessSyncStopped(Carbon::parse('2026-12-10 08:00')->toImmutable(), 'apple_health'))->toMail($quiet);
        $this->assertStringContainsString('Thursday, December 10', implode(' ', $mail->introLines));
    }

    public function test_the_devices_page_shows_the_shortcut_link_and_a_stale_sync_warning(): void
    {
        $user = $this->member();
        $this->actingAs($user)->get('/wellness/connect')->assertOk()->assertSee('the link appears here once it\'s ready', false);

        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin)->put('/wellness/admin/settings', [
            'wellness_enabled' => '1', 'wellness_official_start' => '2027-01-01', 'wellness_grace_days' => 3,
            'wellness_shortcut_url' => 'https://www.icloud.com/shortcuts/abc123',
        ])->assertSessionHas('status');
        $this->assertSame('https://www.icloud.com/shortcuts/abc123', Setting::get('wellness_shortcut_url'));
        $this->actingAs($admin)->put('/wellness/admin/settings', [
            'wellness_enabled' => '1', 'wellness_official_start' => '2027-01-01', 'wellness_grace_days' => 3,
            'wellness_shortcut_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('wellness_shortcut_url');

        Carbon::setTestNow('2026-12-06 20:00:00');
        app(Wellness::class)->record($user, '2026-12-06', ['steps' => 9000], 'apple_health');
        Carbon::setTestNow('2026-12-09 21:00:00');
        $this->actingAs($user)->get('/wellness/connect')->assertOk()
            ->assertSee('https://www.icloud.com/shortcuts/abc123')
            ->assertSee('Apple Health hasn\'t synced since Sunday, Dec 6', false);
    }
}
