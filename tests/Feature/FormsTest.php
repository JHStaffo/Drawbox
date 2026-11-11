<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\PermissionProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\Slack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FormsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSlack(array $post = ['ok' => true, 'ts' => '1731337200.000100']): void
    {
        Http::fake([
            'slack.com/api/auth.test' => Http::response(['ok' => true, 'team' => 'Fast Track EMS', 'user' => 'fast_track_pulse', 'url' => 'https://fasttrack.slack.com/']),
            'slack.com/api/conversations.list' => Http::response(['ok' => true, 'channels' => [['id' => 'C111', 'name' => 'dispatch-log'], ['id' => 'C222', 'name' => 'Facility-calls']]]),
            'slack.com/api/chat.postMessage' => Http::response($post),
        ]);
    }

    private function connect(): void
    {
        app(Slack::class)->saveToken('xoxb-1-test-token');
    }

    private function dispatcherWithForms(): User
    {
        $profile = PermissionProfile::where('name', 'Dispatcher')->first();
        $profile->update(['permissions' => [...$profile->permissions, 'forms']]);

        return User::factory()->role('dispatcher')->create(['name' => 'Dana Dispatch']);
    }

    private function form(): Form
    {
        $form = Form::create(['name' => 'Facility Call Log', 'slack_channel' => 'C111', 'slack_channel_name' => 'dispatch-log', 'active' => true]);
        $form->fields()->create(['label' => 'Facility', 'type' => 'text', 'required' => true, 'position' => 1]);
        $form->fields()->create(['label' => 'Level', 'type' => 'select', 'options' => "BLS\nALS", 'required' => true, 'position' => 2]);
        $form->fields()->create(['label' => 'Needs', 'type' => 'checkboxes', 'options' => "Stair chair\nOxygen", 'position' => 3]);
        $form->fields()->create(['label' => 'Notes', 'type' => 'textarea', 'position' => 4]);

        return $form->load('fields');
    }

    public function test_new_permissions_start_with_admin_only(): void
    {
        $this->assertTrue(PermissionProfile::where('name', 'Admin')->first()->allows('manage_forms'));
        $this->assertTrue(PermissionProfile::where('name', 'Admin')->first()->allows('forms'));
        $this->assertFalse(PermissionProfile::where('name', 'Dispatcher')->first()->allows('forms'));

        $this->actingAs(User::factory()->role('dispatcher')->create())->get('/forms')->assertForbidden();
        $this->actingAs(User::factory()->role('supervisor')->create())->get('/admin/slack')->assertForbidden();
        $this->actingAs(User::factory()->role('admin')->create())->get('/admin/forms')->assertOk()->assertSee('Slack isn');
    }

    public function test_admin_connects_slack_and_the_token_is_kept_secret(): void
    {
        $this->fakeSlack();
        $admin = User::factory()->role('admin')->create();

        $this->actingAs($admin)->put('/admin/slack', ['token' => 'https://hooks.slack.com/x'])->assertSessionHasErrors('token');
        $this->actingAs($admin)->put('/admin/slack', ['token' => 'xoxb-123-abc-secret'])
            ->assertSessionHas('status', 'Connected to the Fast Track EMS Slack workspace as fast_track_pulse.');

        $this->assertSame('xoxb-123-abc-secret', app(Slack::class)->token());
        // The encrypted token is ~290 characters; MySQL would refuse it in a 255-character column.
        $this->assertSame('text', \Illuminate\Support\Facades\Schema::getColumnType('settings', 'value'));
        $this->assertStringNotContainsString('secret', Setting::find('slack_bot_token')->value);
        $log = AuditLog::latest('id')->first();
        $this->assertStringContainsString('Connected to the Fast Track EMS Slack workspace', $log->description);
        $this->assertStringContainsString('Slack bot token from none to set', $log->description);
        $this->assertStringNotContainsString('secret', AuditLog::pluck('description')->implode(' '));

        $this->actingAs($admin)->get('/admin/slack')->assertOk()->assertSee('xoxb-…cret')->assertSee('#dispatch-log');

        // Test message to a channel.
        $this->actingAs($admin)->post('/admin/slack/test', ['channel' => 'C111'])->assertSessionHas('status', 'Test message sent to #dispatch-log. Check Slack.');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'chat.postMessage') && $r['channel'] === 'C111' && $r->hasHeader('Authorization', 'Bearer xoxb-123-abc-secret'));
        $this->assertSame('admin.slack.test', AuditLog::latest('id')->value('action'));

        $this->actingAs($admin)->delete('/admin/slack');
        $this->assertFalse(app(Slack::class)->connected());
    }

    public function test_slack_rejecting_a_token_or_channel_says_why(): void
    {
        Http::fake([
            'slack.com/api/auth.test' => Http::response(['ok' => false, 'error' => 'invalid_auth']),
            'slack.com/api/conversations.list' => Http::response(['ok' => false, 'error' => 'missing_scope', 'needed' => 'channels:read']),
            'slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'not_in_channel']),
        ]);
        $admin = User::factory()->role('admin')->create();

        $this->actingAs($admin)->put('/admin/slack', ['token' => 'xoxb-bad'])->assertSessionHasErrors(['token' => 'Slack rejected the bot token (invalid_auth). Paste a new one in Admin > Slack.']);
        $this->assertFalse(app(Slack::class)->connected());

        $this->connect();
        $this->actingAs($admin)->get('/admin/slack')->assertOk()->assertSee('type the channel ID');
        $this->actingAs($admin)->post('/admin/slack/test', ['channel' => 'C999'])->assertSessionHas('error');
        $this->assertStringContainsString('/invite', session('error'));
        $this->assertStringContainsString('Refused', AuditLog::latest('id')->value('description'));
    }

    public function test_admin_builds_a_form(): void
    {
        $this->fakeSlack();
        $this->connect();
        $admin = User::factory()->role('admin')->create();

        $this->actingAs($admin)->post('/admin/forms', ['name' => 'Facility Call Log'])->assertRedirect();
        $form = Form::first();
        $this->assertFalse($form->active);

        $this->actingAs($admin)->get("/admin/forms/{$form->id}")->assertOk()->assertSee('#dispatch-log');
        $this->actingAs($admin)->put("/admin/forms/{$form->id}", ['name' => 'Facility Call Log', 'slack_channel' => 'C111', 'active' => '1'])->assertSessionHasNoErrors();
        $form->refresh();
        $this->assertSame('dispatch-log', $form->slack_channel_name);
        $this->assertTrue($form->active);
        $this->assertStringContainsString('Facility Call Log form: Slack channel from none to C111, on from no to yes.', AuditLog::latest('id')->value('description'));

        $this->actingAs($admin)->post("/admin/forms/{$form->id}/fields", ['label' => 'Level', 'type' => 'select'])->assertSessionHasErrors('options');
        $this->actingAs($admin)->post("/admin/forms/{$form->id}/fields", ['label' => 'Facility', 'type' => 'text', 'required' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/admin/forms/{$form->id}/fields", ['label' => 'Level', 'type' => 'select', 'options' => "BLS\r\nALS\r\n"])->assertSessionHasNoErrors();
        $this->assertSame(['Facility', 'Level'], $form->fields()->pluck('label')->all());
        $this->assertSame(['BLS', 'ALS'], $form->fields()->get()[1]->choices());

        $level = $form->fields()->where('label', 'Level')->first();
        $this->actingAs($admin)->post("/admin/form-fields/{$level->id}/move", ['direction' => 'up']);
        $this->assertSame(['Level', 'Facility'], $form->fields()->pluck('label')->all());
        $this->assertSame('Moved "Level" up on the Facility Call Log form.', AuditLog::latest('id')->value('description'));

        $this->actingAs($admin)->put("/admin/form-fields/{$level->id}", ['label' => 'Level of care', 'type' => 'radio', 'options' => "BLS\nALS", 'required' => '1']);
        $this->assertStringContainsString('"Level" on the Facility Call Log form: question from Level to Level of care', AuditLog::latest('id')->value('description'));

        // The test button posts a sample with every question.
        $this->actingAs($admin)->post("/admin/forms/{$form->id}/test")->assertSessionHas('status');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'chat.postMessage') && $r['channel'] === 'C111' && str_contains(json_encode($r['blocks']), 'TEST: Facility Call Log'));

        $this->actingAs($admin)->delete("/admin/forms/{$form->id}")->assertRedirect('/admin/forms');
        $this->assertNull(Form::find($form->id));
    }

    public function test_a_submission_is_saved_and_posted_to_slack(): void
    {
        $this->fakeSlack();
        $this->connect();
        $dana = $this->dispatcherWithForms();
        $form = $this->form();
        [$facility, $level, $needs, $notes] = $form->fields->all();

        $this->actingAs($dana)->get('/forms')->assertOk()->assertSee('Facility Call Log')->assertSee('#dispatch-log');
        $this->actingAs($dana)->get("/forms/{$form->id}")->assertOk()->assertSee('Stair chair');

        $this->actingAs($dana)->post("/forms/{$form->id}", ["f{$level->id}" => 'MICU'])->assertSessionHasErrors(["f{$facility->id}", "f{$level->id}"]);
        $this->assertSame(0, FormSubmission::count());

        $this->actingAs($dana)->post("/forms/{$form->id}", [
            "f{$facility->id}" => 'Sentara <Leigh> & Co',
            "f{$level->id}" => 'ALS',
            "f{$needs->id}" => ['Stair chair', 'Oxygen'],
            "f{$notes->id}" => '',
        ])->assertSessionHas('status', 'Sent. Facility Call Log was posted to #dispatch-log.');

        $s = FormSubmission::first();
        $this->assertSame('sent', $s->slack_status);
        $this->assertSame('1731337200.000100', $s->slack_ts);
        $this->assertSame($dana->id, $s->user_id);
        $this->assertSame(['Facility', 'Sentara <Leigh> & Co'], [$s->answers[0]['label'], $s->answers[0]['value']]);
        $this->assertSame('Stair chair, Oxygen', $s->answers[2]['value']);
        $this->assertNull($s->answers[3]['value']);

        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), 'chat.postMessage')) {
                return false;
            }
            $blocks = json_encode($r['blocks']);

            return $r['channel'] === 'C111' && $r['text'] === 'Facility Call Log from Dana Dispatch'
                && str_contains($blocks, 'Sentara &lt;Leigh&gt; &amp; Co')
                && str_contains($blocks, 'Sent by Dana Dispatch from Fast Track Pulse')
                && ! str_contains($blocks, 'Notes');
        });
        $this->assertSame("Sent the Facility Call Log form (#{$s->id}) and posted it to Slack.", AuditLog::where('action', 'forms.store')->latest('id')->value('description'));

        // Editing the form later doesn't change what was sent.
        $facility->update(['label' => 'Pickup facility']);
        $this->assertSame('Facility', $s->fresh()->answers[0]['label']);
        $this->actingAs($dana)->get('/forms')->assertSee('Posted to Slack');

        // Turned-off forms can't be filled out.
        $form->update(['active' => false]);
        $this->actingAs($dana)->get("/forms/{$form->id}")->assertNotFound();
    }

    public function test_a_submission_slack_refuses_is_kept_and_retried(): void
    {
        Http::fakeSequence('slack.com/api/chat.postMessage')
            ->push(['ok' => false, 'error' => 'not_in_channel'])
            ->push(['ok' => true, 'ts' => '1.2']);
        $this->connect();
        $dana = $this->dispatcherWithForms();
        $form = $this->form();
        [$facility, $level] = $form->fields->all();

        $this->actingAs($dana)->post("/forms/{$form->id}", ["f{$facility->id}" => 'Sentara', "f{$level->id}" => 'BLS'])->assertSessionHas('error');
        $s = FormSubmission::first();
        $this->assertSame('failed', $s->slack_status);
        $this->assertStringContainsString('/invite', $s->slack_error);
        $this->assertStringContainsString("Slack didn't take it", AuditLog::where('action', 'forms.store')->latest('id')->value('description'));

        $this->travel(5)->minutes();
        $this->artisan('pulse:slack-retry')->assertSuccessful();
        $this->assertSame('sent', $s->fresh()->slack_status);
        $this->assertSame(2, $s->fresh()->slack_attempts);
        $this->assertSame("Posted 1 form submission(s) to Slack that didn't go through the first time.", AuditLog::where('action', 'system.slack-retry')->value('description'));
    }

    public function test_admin_can_post_a_stuck_submission_by_hand(): void
    {
        $this->fakeSlack();
        $this->connect();
        $admin = User::factory()->role('admin')->create();
        $form = $this->form();
        $s = $form->submissions()->create(['user_id' => $admin->id, 'form_name' => $form->name, 'answers' => [['label' => 'Facility', 'type' => 'text', 'value' => 'Sentara']], 'slack_status' => 'failed', 'slack_channel' => 'C111', 'slack_attempts' => 5]);

        $this->actingAs($admin)->get('/admin/forms/submissions?status=failed')->assertOk()->assertSee('Sentara')->assertSee('Post now');
        $this->actingAs($admin)->post("/admin/forms/submissions/{$s->id}/resend")->assertSessionHas('status', "Posted Facility Call Log #{$s->id} to Slack.");
        $this->assertSame('sent', $s->fresh()->slack_status);
        $this->assertSame("Posted Facility Call Log #{$s->id} to Slack.", AuditLog::latest('id')->value('description'));

        // A form with submissions can't be deleted.
        $this->actingAs($admin)->delete("/admin/forms/{$form->id}")->assertSessionHas('error');
        $this->assertNotNull($form->fresh());
    }

    public function test_a_form_without_a_channel_just_saves(): void
    {
        Http::fake();
        $dana = $this->dispatcherWithForms();
        $form = Form::create(['name' => 'Shift notes', 'active' => true]);
        $field = $form->fields()->create(['label' => 'Note', 'type' => 'textarea']);

        $this->actingAs($dana)->post("/forms/{$form->id}", ["f{$field->id}" => 'All quiet'])->assertSessionHas('status', 'Saved Shift notes.');
        $this->assertSame('off', FormSubmission::first()->slack_status);
        Http::assertNothingSent();
    }
}
