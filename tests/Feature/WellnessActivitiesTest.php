<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PermissionProfile;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\WellnessRewardEarned;
use App\Wellness\Models\Award;
use App\Wellness\Models\Participant;
use App\Wellness\Models\Point;
use App\Wellness\Models\Submission;
use App\Wellness\Rewards;
use App\Wellness\Wellness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WellnessActivitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday, December 9, 2026.
        Carbon::setTestNow('2026-12-09 15:00:00');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $name = 'Jamie Medic'): User
    {
        $profile = PermissionProfile::where('name', 'Team Member')->first();
        if (! in_array('wellness', $profile->permissions, true)) {
            $profile->update(['permissions' => [...$profile->permissions, 'wellness']]);
        }
        $user = User::factory()->role('employee')->create(['name' => $name]);
        Participant::create(['user_id' => $user->id, 'joined_at' => now(), 'consent_version' => '2026-11-01', 'consent_at' => now()]);

        return $user;
    }

    private function note(): UploadedFile
    {
        return UploadedFile::fake()->image('note.jpg', 600, 800);
    }

    public function test_a_visit_needs_a_note_waits_for_review_and_scores_within_its_cap(): void
    {
        $user = $this->member();
        $admin = User::factory()->role('admin')->create(['name' => 'Ada Admin']);

        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'dentist', 'day' => '2026-12-03'])->assertSessionHasErrors('note');
        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'dentist', 'day' => '2026-12-03', 'note' => $this->note()])
            ->assertSessionHas('status', 'Sent your Dentist visit on Dec 3, 2026 for review. You\'ll see the result here.');
        $s = Submission::first();
        $this->assertSame('pending', $s->status);
        $this->assertSame(0, (int) Point::sum('points'));

        // Stored encrypted, outside the web folder.
        $stored = Storage::disk('local')->get($s->note_path);
        $this->assertStringNotContainsString('JFIF', $stored);
        $this->assertStringStartsWith('wellness/notes/', $s->note_path);

        $this->actingAs($admin)->get("/wellness/admin/review/{$s->id}/note")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame("Opened the note for Jamie Medic's Dentist visit on Dec 3, 2026.", AuditLog::where('action', 'wellness.note.view')->first()->description);
        $this->actingAs(User::factory()->role('supervisor')->create())->get("/wellness/admin/review/{$s->id}/note")->assertForbidden();
        $this->actingAs($this->member('Other Person'))->get("/wellness/activities/{$s->id}/note")->assertNotFound();

        $this->actingAs($admin)->post("/wellness/admin/review/{$s->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(185, (int) Point::where('user_id', $user->id)->sum('points'));
        $this->assertStringContainsString("Approved Jamie Medic's Dentist visit on Dec 3, 2026: 185 points.", AuditLog::where('action', 'wellness.admin.review.approve')->first()->description);

        // Second dentist visit fills the 370 a year; a third gets nothing.
        foreach (['2026-12-07', '2026-12-08'] as $day) {
            $this->actingAs($user)->post('/wellness/activities', ['activity' => 'dentist', 'day' => $day, 'note' => $this->note()]);
            $this->actingAs($admin)->post('/wellness/admin/review/'.Submission::latest('id')->first()->id.'/approve');
        }
        $this->assertSame(370, (int) Point::where('user_id', $user->id)->sum('points'));
        $this->assertStringContainsString('limit of 370 points a year is already reached', Submission::latest('id')->first()->points_note);

        // The same activity on the same day can't be sent twice.
        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'dentist', 'day' => '2026-12-03', 'note' => $this->note()])->assertSessionHasErrors('day');
    }

    public function test_sent_back_with_a_reason_then_fixed_and_resent(): void
    {
        $user = $this->member();
        $admin = User::factory()->role('admin')->create();
        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'self_care', 'day' => '2026-12-08', 'note' => $this->note()]);
        $s = Submission::first();

        $this->actingAs($admin)->post("/wellness/admin/review/{$s->id}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post("/wellness/admin/review/{$s->id}/reject", ['reason' => 'The note has no signature'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $s->fresh()->status);
        $this->actingAs($user)->get('/wellness/activities')->assertSee('The note has no signature')->assertSee('Fix and resend');
        $this->actingAs($user)->get("/wellness/activities?fix={$s->id}")->assertSee('Fix and send again');

        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'self_care', 'day' => '2026-12-08', 'note' => $this->note(), 'fix' => $s->id])->assertSessionHasNoErrors();
        $fixed = Submission::latest('id')->first();
        $this->assertSame($s->id, $fixed->resubmission_of);
        $this->actingAs($admin)->get('/wellness/admin/review')->assertSee('fixing one sent back for: The note has no signature');
        $this->actingAs($admin)->post("/wellness/admin/review/{$fixed->id}/approve");
        $this->assertSame(10, (int) Point::sum('points'));

        // Self-care is capped at 10 a week.
        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'self_care', 'day' => '2026-12-09', 'note' => $this->note()]);
        $this->actingAs($admin)->post('/wellness/admin/review/'.Submission::latest('id')->first()->id.'/approve');
        $this->assertSame(10, (int) Point::sum('points'));
    }

    public function test_reviewers_cannot_decide_their_own(): void
    {
        $admin = User::factory()->role('admin')->create();
        $profile = PermissionProfile::where('name', 'Admin')->first();
        $profile->update(['permissions' => [...$profile->permissions, 'wellness']]);
        Participant::create(['user_id' => $admin->id, 'joined_at' => now(), 'consent_version' => '2026-11-01', 'consent_at' => now()]);
        $this->actingAs($admin)->post('/wellness/activities', ['activity' => 'eye', 'day' => '2026-12-01', 'note' => $this->note()]);
        $s = Submission::first();

        $this->actingAs($admin)->post("/wellness/admin/review/{$s->id}/approve")->assertSessionHasErrors('submission');
        $this->assertSame('pending', $s->fresh()->status);
        $this->actingAs($admin)->get('/wellness/admin/review')->assertSee('another reviewer has to decide it');
    }

    public function test_inbody_compares_with_the_last_approved_scan(): void
    {
        $user = $this->member();
        $admin = User::factory()->role('admin')->create();

        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'inbody', 'day' => '2026-11-01', 'note' => $this->note(), 'body_fat_pct' => 'abc', 'muscle_lb' => 80])
            ->assertSessionHasErrors('body_fat_pct');
        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'inbody', 'day' => '2026-11-01', 'note' => $this->note(), 'body_fat_pct' => 24.0, 'muscle_lb' => 80])->assertSessionHasNoErrors();
        $first = Submission::first();
        $this->assertStringNotContainsString('24', $first->getRawOriginal('measures'));
        $this->actingAs($admin)->post("/wellness/admin/review/{$first->id}/approve");
        $this->assertSame(0, $first->fresh()->points);
        $this->assertStringContainsString('First scan', $first->fresh()->points_note);

        // Less than 28 days later doesn't count as a comparison point; this one is compared with Nov 1 anyway.
        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'inbody', 'day' => '2026-12-02', 'note' => $this->note(), 'body_fat_pct' => 23.5, 'muscle_lb' => 80.4]);
        $second = Submission::latest('id')->first();
        $this->actingAs($admin)->post("/wellness/admin/review/{$second->id}/approve");
        $this->assertSame(0, $second->fresh()->points);
        $this->assertStringContainsString('No qualifying improvement on the Nov 1, 2026 scan', $second->fresh()->points_note);

        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'inbody', 'day' => '2026-12-09', 'note' => $this->note(), 'body_fat_pct' => 22.9, 'muscle_lb' => 80]);
        $third = Submission::latest('id')->first();
        $this->actingAs($admin)->get('/wellness/admin/review')->assertSee('Body fat 22.9%');
        $this->actingAs($admin)->post("/wellness/admin/review/{$third->id}/approve");
        $this->assertSame(30, $third->fresh()->points);
    }

    public function test_weigh_ins_score_straight_away_and_privately(): void
    {
        $user = $this->member();
        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'weigh_in', 'day' => '2026-12-07'])
            ->assertSessionHas('status', 'Recorded your Weekly weigh-in on Dec 7, 2026: 10 points.');
        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'weigh_in', 'day' => '2026-12-08']);
        $this->assertSame(10, (int) Point::sum('points'));
        $this->assertSame('approved', Submission::latest('id')->first()->status);

        // Other points never count toward the weekly 56.
        $this->assertSame(0, app(\App\Wellness\Progress::class)->week($user)['points']);

        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'weigh_in', 'day' => '2026-12-10'])->assertSessionHasErrors('day');
        $this->actingAs($user)->post('/wellness/activities', ['activity' => 'weigh_in', 'day' => '2026-09-01'])->assertSessionHasErrors('day');
    }

    public function test_adjustments_need_a_reason_and_keep_the_daily_cap(): void
    {
        $user = $this->member();
        $admin = User::factory()->role('admin')->create();
        $p = Participant::where('user_id', $user->id)->first();
        app(Wellness::class)->record($user, '2026-12-08', ['meals' => 3, 'water_oz' => 64, 'meal_plan' => 1, 'steps' => 10000], 'manual');

        $this->actingAs($admin)->post("/wellness/admin/participants/{$p->id}/adjust", ['day' => '2026-12-08', 'category' => 'daily', 'points' => 2])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post("/wellness/admin/participants/{$p->id}/adjust", ['day' => '2026-12-08', 'category' => 'daily', 'points' => 3, 'reason' => 'x'])->assertSessionHasErrors('points');
        $this->actingAs($admin)->post("/wellness/admin/participants/{$p->id}/adjust", ['day' => '2026-12-08', 'category' => 'daily', 'points' => 2, 'reason' => 'Watch outage Dec 8'])->assertSessionHasNoErrors();
        $this->assertSame(8, (int) Point::where('day', '2026-12-08')->sum('points'));
        $this->assertSame("Adjusted Jamie Medic's daily-habit points for Dec 8, 2026 by +2. Reason: Watch outage Dec 8", AuditLog::where('action', 'wellness.admin.adjust')->latest('id')->first()->description);

        // A later sync on the open day can't push it past 8.
        app(Wellness::class)->record($user, '2026-12-08', ['sleep_minutes' => 420, 'exercise_minutes' => 40], 'apple_health');
        $this->assertSame(8, (int) Point::where('day', '2026-12-08')->sum('points'));
        $this->assertSame(1, Point::where('kind', 'adjustment')->count());

        $this->actingAs($admin)->get("/wellness/admin/participants/{$p->id}")->assertOk()->assertSee('Watch outage Dec 8');
    }

    public function test_the_weekly_reward_is_created_once_the_week_closes(): void
    {
        Notification::fake();
        $winner = $this->member('Wendy Winner');
        $close = $this->member('Carl Close');
        $wellness = app(Wellness::class);
        $perfect = ['meals' => 3, 'water_oz' => 64, 'meal_plan' => 1, 'steps' => 10000, 'sleep_minutes' => 420, 'exercise_minutes' => 30];
        // Week of Sunday Nov 29 to Saturday Dec 5.
        Carbon::setTestNow('2026-12-05 21:00:00');
        foreach (range(0, 6) as $i) {
            $day = Carbon::parse('2026-11-29')->addDays($i)->toDateString();
            $wellness->record($winner, $day, $perfect, 'manual');
            $wellness->record($close, $day, $i === 3 ? ['meals' => 2] + $perfect : $perfect, 'manual');
        }
        // Other points don't make up Carl's missing point.
        $this->actingAs($close)->post('/wellness/activities', ['activity' => 'weigh_in', 'day' => '2026-12-01']);

        Carbon::setTestNow('2026-12-08 06:00:00');
        $this->assertSame(0, app(Rewards::class)->closeWeeks()); // still in the grace period
        Carbon::setTestNow('2026-12-09 06:00:00');
        $this->artisan('wellness:close-weeks')->assertSuccessful();
        $this->artisan('wellness:close-weeks')->assertSuccessful();

        $this->assertSame(1, Award::count());
        $award = Award::first();
        $this->assertSame($winner->id, $award->user_id);
        $this->assertSame(56, $award->points);
        $this->assertSame(1000, $award->value_cents);
        $this->assertTrue($award->test_period);
        Notification::assertSentTo($winner, WellnessRewardEarned::class);
        Notification::assertNotSentTo($close, WellnessRewardEarned::class);
        $this->assertStringContainsString('1 Team Member(s) earned the weekly Wellness gift card', AuditLog::where('action', 'system.wellness-weeks')->first()->description);

        // The Team Member picks a card, an admin sends it. (A new day: sign in again.)
        $this->flushSession();
        $this->actingAs($winner)->get('/wellness')->assertSee('Pick your card');
        $this->actingAs($winner)->post("/wellness/rewards/{$award->id}/brand", ['brand' => 'Bitcoin'])->assertSessionHasErrors('brand');
        $this->actingAs($winner)->post("/wellness/rewards/{$award->id}/brand", ['brand' => 'Target'])->assertSessionHasNoErrors();
        $this->actingAs($close)->post("/wellness/rewards/{$award->id}/brand", ['brand' => 'Amazon'])->assertForbidden();

        $admin = User::factory()->role('admin')->create(['name' => 'Ada Admin']);
        $this->actingAs($admin)->get('/wellness/admin/rewards')->assertOk()->assertSee('Wendy Winner')->assertSee('Target');
        $this->actingAs($admin)->post("/wellness/admin/rewards/{$award->id}/sent", ['note' => 'Emailed e-card'])->assertSessionHasNoErrors();
        $this->assertSame('sent', $award->fresh()->status);
        $this->actingAs($admin)->post("/wellness/admin/rewards/{$award->id}/void", ['reason' => 'oops'])->assertSessionHasErrors('award');

        $csv = $this->actingAs($admin)->get('/wellness/admin/rewards.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Wendy Winner', $csv);
        $this->assertStringContainsString('2026-11-29,2026-12-05,56,10.00,yes,Target,Sent', $csv);
        $week = $this->actingAs($admin)->get('/wellness/admin/reports/week.csv?week=2026-12-02')->assertOk()->streamedContent();
        $this->assertStringContainsString('Carl Close', $week);
        $this->assertMatchesRegularExpression('/"Carl Close",[^\n]*,55,10,65,no/', $week);
        $this->assertMatchesRegularExpression('/"Wendy Winner",[^\n]*,56,0,56,yes/', $week);
    }

    public function test_the_app_can_send_activities_and_pick_a_card(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user, ['wellness']);

        $this->post('/api/v1/wellness/submissions', ['activity' => 'primary_care', 'day' => '2026-12-04', 'note' => $this->note()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('submission.status', 'pending')->assertJsonPath('submission.has_note', true);
        $this->getJson('/api/v1/wellness/submissions')->assertOk()->assertJsonPath('submissions.0.activity', 'primary_care');
        $id = Submission::first()->id;
        $this->postJson("/api/v1/wellness/submissions/{$id}/withdraw")->assertOk()->assertJsonPath('submission.status', 'withdrawn');

        $award = Award::create(['user_id' => $user->id, 'period_start' => '2026-11-29', 'period_end' => '2026-12-05', 'points' => 56, 'value_cents' => 1000]);
        $this->getJson('/api/v1/wellness/awards')->assertOk()->assertJsonPath('awards.0.status', 'pending')->assertJsonPath('brands.0', 'Amazon');
        $this->putJson("/api/v1/wellness/awards/{$award->id}/brand", ['brand' => 'Walmart'])->assertOk()->assertJsonPath('award.brand', 'Walmart');
    }

    public function test_new_pages_open_and_stay_behind_their_permissions(): void
    {
        $user = $this->member();
        $admin = User::factory()->role('admin')->create();
        $p = Participant::first();
        $this->actingAs($user)->get('/wellness/activities')->assertOk();
        foreach (['/wellness/admin/review', '/wellness/admin/rewards', "/wellness/admin/participants/{$p->id}", '/wellness/admin/settings'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
            $this->actingAs($user)->get($url)->assertForbidden();
        }
        $this->actingAs($admin)->put('/wellness/admin/settings', [
            'wellness_enabled' => '1', 'wellness_official_start' => '2027-01-01', 'wellness_grace_days' => 3, 'wellness_submit_days' => 60,
            'reward_dollars' => 15, 'wellness_gift_brands' => "Target\n\nAmazon\nTarget", 'wellness_inbody_min_days' => 28,
            'wellness_inbody_fat_drop' => 1, 'wellness_inbody_muscle_gain' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame('1500', Setting::get('wellness_weekly_reward_cents'));
        $this->assertSame(['Target', 'Amazon'], Rewards::brands());
        $this->assertStringContainsString('Wellness: weekly reward from $10 to $15', AuditLog::where('action', 'wellness.admin.settings.update')->latest('id')->first()->description);
    }
}
