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

    public function test_a_form_can_have_its_own_slack_message(): void
    {
        $this->fakeSlack();
        $this->connect();
        $admin = User::factory()->role('admin')->create();
        $dana = $this->dispatcherWithForms();
        $form = $this->form();
        [$facility, $level, $needs, $notes] = $form->fields->all();

        $template = ":rotating_light: *\$answer2 call* at \$answer1 <!here>\nNeeds: \$answer3\nNotes: \$answer4\nLogged by \$sender for \$form (\$answer9)";
        $this->actingAs($admin)->put("/admin/forms/{$form->id}", ['name' => $form->name, 'slack_channel' => 'C111', 'slack_template' => $template, 'active' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get("/admin/forms/{$form->id}")->assertSee('$answer2')->assertSee('Level');

        $this->actingAs($dana)->post("/forms/{$form->id}", ["f{$facility->id}" => 'Sentara <Leigh>', "f{$level->id}" => 'ALS', "f{$needs->id}" => ['Oxygen']]);
        $s = FormSubmission::first();
        $this->assertSame('sent', $s->slack_status);
        $this->assertSame($template, $s->slack_template);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'chat.postMessage')
            && $r['blocks'][0]['text']['text'] === ":rotating_light: *ALS call* at Sentara &lt;Leigh&gt; <!here>\nNeeds: Oxygen\nNotes: \nLogged by Dana Dispatch for Facility Call Log (\$answer9)"
            && count($r['blocks']) === 1);

        // Moving a question keeps the numbers on the same answers; removing one drops it.
        $this->actingAs($admin)->post("/admin/form-fields/{$level->id}/move", ['direction' => 'up']);
        $this->assertStringStartsWith(':rotating_light: *$answer1 call* at $answer2', $form->fresh()->slack_template);
        $this->actingAs($admin)->delete("/admin/form-fields/{$needs->id}");
        $this->assertStringContainsString("Needs: \nNotes: \$answer3", $form->fresh()->slack_template);
        $this->assertStringContainsString('Slack message from', \App\Models\AuditLog::latest('id')->value('description'));

        // The test button fills in each question's name.
        $this->actingAs($admin)->post("/admin/forms/{$form->id}/test")->assertSessionHas('status');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'chat.postMessage') && str_starts_with($r['blocks'][0]['text']['text'], "*TEST*\n:rotating_light: *[Level] call* at [Facility]"));
    }

    public function test_choice_questions_can_offer_other_with_a_box_to_type_in(): void
    {
        $this->fakeSlack();
        $this->connect();
        $admin = User::factory()->role('admin')->create();
        $dana = $this->dispatcherWithForms();
        $form = Form::create(['name' => 'Unable to accommodate', 'slack_channel' => 'C111', 'active' => true]);

        $this->actingAs($admin)->post("/admin/forms/{$form->id}/fields", ['label' => 'Reason', 'type' => 'radio', 'options' => "Truck not available\nNo ALS available", 'required' => '1', 'allow_other' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/admin/forms/{$form->id}/fields", ['label' => 'Where', 'type' => 'select', 'options' => "Sentara\nRiverside", 'allow_other' => '1']);
        $this->actingAs($admin)->post("/admin/forms/{$form->id}/fields", ['label' => 'Needs', 'type' => 'checkboxes', 'options' => "Oxygen", 'allow_other' => '1']);
        $this->actingAs($admin)->post("/admin/forms/{$form->id}/fields", ['label' => 'Notes', 'type' => 'text', 'allow_other' => '1']);
        [$reason, $where, $needs, $notes] = $form->fields()->get()->all();
        $this->assertTrue($reason->allow_other);
        $this->assertFalse($notes->allow_other); // only choice questions get Other

        $this->actingAs($dana)->get("/forms/{$form->id}")->assertOk()->assertSee('Other:')->assertSee('Other…', false);

        // Picking Other means typing something.
        $this->actingAs($dana)->post("/forms/{$form->id}", ["f{$reason->id}" => '__other'])->assertSessionHasErrors("f{$reason->id}_other");
        $this->actingAs($dana)->post("/forms/{$form->id}", ["f{$reason->id}" => 'Made up'])->assertSessionHasErrors("f{$reason->id}");

        $this->actingAs($dana)->post("/forms/{$form->id}", [
            "f{$reason->id}" => '__other', "f{$reason->id}_other" => 'Patient refused',
            "f{$where->id}" => '__other', "f{$where->id}_other" => 'Bon Secours',
            "f{$needs->id}" => ['Oxygen', '__other'], "f{$needs->id}_other" => 'Stair chair',
        ])->assertSessionHasNoErrors();
        $answers = collect(FormSubmission::first()->answers)->pluck('value')->all();
        $this->assertSame(['Patient refused', 'Bon Secours', 'Oxygen, Stair chair', null], $answers);
    }

    public function test_form_reports_filter_and_total_submissions(): void
    {
        Http::fake();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-11 10:00', 'America/New_York'));
        $admin = User::factory()->role('admin')->create(['name' => 'Ada Admin']);
        $dana = $this->dispatcherWithForms();
        $member = PermissionProfile::where('name', 'Team Member')->first();
        $emt = PermissionProfile::create(['name' => 'EMT', 'parent_id' => $member->id, 'permissions' => []]);
        $amy = User::factory()->create(['name' => 'Amy Medic', 'permission_profile_id' => $emt->id]);
        $bob = User::factory()->create(['name' => 'Bob Driver']);
        $form = Form::create(['name' => 'Attendance', 'active' => true]);
        $who = $form->fields()->create(['label' => 'Team Member', 'type' => 'team_member', 'required' => true, 'position' => 1]);
        $type = $form->fields()->create(['label' => 'Type of report', 'type' => 'radio', 'options' => "Late for Work\nCall Out", 'required' => true, 'position' => 2]);
        $other = Form::create(['name' => 'Shift notes', 'active' => true]);
        $note = $other->fields()->create(['label' => 'Note', 'type' => 'text', 'position' => 1]);

        $send = fn ($user, $f, $data) => $this->actingAs($user)->post("/forms/{$f->id}", $data)->assertSessionHasNoErrors();
        $send($dana, $form, ["f{$who->id}" => $amy->id, "f{$type->id}" => 'Late for Work']);
        $send($dana, $form, ["f{$who->id}" => $amy->id, "f{$type->id}" => 'Call Out']);
        $send($admin, $form, ["f{$who->id}" => $bob->id, "f{$type->id}" => 'Call Out']);
        $send($dana, $other, ["f{$note->id}" => 'Quiet night']);
        $this->travel(-1)->months();
        $send($dana, $form, ["f{$who->id}" => $bob->id, "f{$type->id}" => 'Late for Work']);
        $this->travelBack();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-11 10:05', 'America/New_York'));

        $this->assertSame($amy->id, FormSubmission::first()->subject_user_id);
        $this->assertSame($dana->id, FormSubmission::where('form_name', 'Shift notes')->value('subject_user_id'));

        $this->actingAs($dana)->get('/forms/reports')->assertForbidden();
        $this->actingAs($admin)->get('/forms/reports')->assertOk()->assertSee('4 submissions');

        $page = $this->actingAs($admin)->get("/forms/reports?period=month&form={$form->id}&group=".urlencode('q:Type of report'));
        $page->assertOk()->assertSee('3 submissions')->assertSee('Totals by Type of report');
        $this->assertEquals([['label' => 'Call Out', 'count' => 2], ['label' => 'Late for Work', 'count' => 1]], $page->viewData('totals')->all());

        // By position: EMT is a sub-profile of Team Member, so Team Member includes Amy.
        $this->assertSame(2, $this->actingAs($admin)->get("/forms/reports?form={$form->id}&position={$emt->id}")->viewData('submissions')->count());
        $this->assertSame(3, $this->actingAs($admin)->get("/forms/reports?form={$form->id}&position={$member->id}")->viewData('submissions')->count());
        $this->assertEquals([['label' => 'Team Member › EMT', 'count' => 2], ['label' => 'Team Member', 'count' => 1]], $this->actingAs($admin)->get("/forms/reports?form={$form->id}&group=position")->viewData('totals')->all());

        // By team member, sender, and custom dates reaching back to last month.
        $this->assertSame(1, $this->actingAs($admin)->get("/forms/reports?member={$bob->id}")->viewData('submissions')->count());
        $this->assertSame(1, $this->actingAs($admin)->get("/forms/reports?sender={$admin->id}")->viewData('submissions')->count());
        $this->assertSame(2, $this->actingAs($admin)->get("/forms/reports?period=custom&from=2026-10-01&to=2026-11-30&member={$bob->id}")->viewData('submissions')->count());
        $this->assertSame(1, $this->actingAs($admin)->get('/forms/reports?period=last_month')->viewData('submissions')->count());
        $this->assertSame(4, $this->actingAs($admin)->get('/forms/reports?period=today')->viewData('submissions')->count());

        $csv = $this->actingAs($admin)->get("/forms/reports.csv?form={$form->id}&member={$amy->id}")->streamedContent();
        $this->assertStringContainsString('"Team Member",Position,"Sent by",Slack,"Team Member","Type of report"', $csv);
        $this->assertStringContainsString('"Amy Medic","Team Member › EMT","Dana Dispatch"', $csv);
        $this->assertSame('Exported form report', \App\Models\AuditLog::latest('id')->first()->label());
    }

    public function test_reports_follow_the_chosen_forms_own_questions(): void
    {
        Http::fake();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-11 10:00', 'America/New_York'));
        $admin = User::factory()->role('admin')->create(['name' => 'Ada Admin']);
        $dana = $this->dispatcherWithForms();
        $facilities = \App\Models\OptionList::where('name', 'Facilities')->first();
        $facilities->items()->createMany([['name' => 'Riverside Regional'], ['name' => 'Sentara Norfolk']]);
        $form = Form::create(['name' => 'Missed calls', 'active' => true]);
        $where = $form->fields()->create(['label' => 'Where', 'type' => 'list', 'option_list_id' => $facilities->id, 'required' => true, 'position' => 1]);
        $reason = $form->fields()->create(['label' => 'Reason', 'type' => 'select', 'options' => "No trucks\nNo crew", 'allow_other' => true, 'required' => true, 'position' => 2]);
        $eta = $form->fields()->create(['label' => 'ETA given', 'type' => 'text', 'position' => 3]);

        $send = fn ($w, $r, $e, $other = null) => $this->actingAs($dana)->post("/forms/{$form->id}", array_filter([
            "f{$where->id}" => $w, "f{$reason->id}" => $r, "f{$eta->id}" => $e, $reason->otherInputName() => $other,
        ]))->assertSessionHasNoErrors();
        $send('Riverside Regional', 'No trucks', '2 hours');
        $send('riverside  regional', 'No crew', '90 minutes');
        $send('Sentara Norfolk', 'No trucks', '3 hours');
        $send('Sentara Norfolk', \App\Models\FormField::OTHER, '', 'Weather');

        $base = "/forms/reports?form={$form->id}";
        $page = $this->actingAs($admin)->get($base)->assertOk();
        // No Team Member question, so no Team Member or Position filters, and totals start on the form's first question.
        $page->assertDontSee('id="member"', false)->assertDontSee('id="position"', false)->assertSee('Then split by');
        $this->assertSame('q:Where', $page->viewData('group'));
        $this->assertEquals([['label' => 'Riverside Regional', 'count' => 2], ['label' => 'Sentara Norfolk', 'count' => 2]], $page->viewData('totals')->all());
        $this->assertArrayNotHasKey('member', $page->viewData('groups'));

        $count = fn ($qs) => $this->actingAs($admin)->get($base.'&'.$qs)->viewData('submissions')->count();
        $this->assertSame(2, $count('q[Reason]=No+trucks'));
        $this->assertSame(1, $count('q[Reason]=__other'));
        $this->assertSame(1, $count('q[Where]=Sentara+Norfolk&q[Reason]=No+trucks'));
        $this->assertSame(1, $count('q[ETA+given]=hour&q[Where]=Riverside+Regional'));
        // A filter left over from another form is ignored.
        $this->assertSame(4, $count('q[Type+of+report]=Call+Out'));

        $matrix = $this->actingAs($admin)->get($base.'&group=q:Where&split=q:Reason')->assertOk()->assertSee('Where by Reason')->viewData('matrix');
        $this->assertSame(['No trucks', 'No crew', 'Weather'], $matrix['columns']); // Most used first, then A to Z.
        $this->assertEquals(['label' => 'Sentara Norfolk', 'cells' => ['No trucks' => 1, 'Weather' => 1], 'total' => 2], $matrix['rows'][1]);
        $this->assertSame(['No trucks' => 2, 'No crew' => 1, 'Weather' => 1], $matrix['totals']);

        $csv = $this->actingAs($admin)->get("/forms/reports.csv?form={$form->id}&q[Reason]=No+crew")->streamedContent();
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1);
    }

    public function test_shared_list_questions_pick_or_add_entries(): void
    {
        Http::fake();
        $dana = $this->dispatcherWithForms();
        $facilities = \App\Models\OptionList::where('name', 'Facilities')->first();
        $facilities->items()->create(['name' => 'Riverside Regional']);
        $facilities->items()->create(['name' => 'Old Clinic', 'active' => false]);
        $form = Form::create(['name' => 'Missed calls', 'active' => true]);
        $where = $form->fields()->create(['label' => 'Where', 'type' => 'list', 'option_list_id' => $facilities->id, 'required' => true, 'position' => 1]);

        $this->actingAs($dana)->get("/forms/{$form->id}")->assertOk()
            ->assertSee('<option value="Riverside Regional">', false)->assertDontSee('Old Clinic');

        // Typing an existing entry in other capitals uses the list's spelling.
        $this->actingAs($dana)->post("/forms/{$form->id}", ["f{$where->id}" => ' riverside REGIONAL '])->assertSessionHasNoErrors();
        $this->assertSame('Riverside Regional', FormSubmission::latest('id')->first()->answer('Where'));

        // A new one is added to the list, and the audit log says so.
        $this->actingAs($dana)->post("/forms/{$form->id}", ["f{$where->id}" => 'Bon Secours Mary Immaculate'])->assertSessionHasNoErrors();
        $added = $facilities->items()->where('name', 'Bon Secours Mary Immaculate')->first();
        $this->assertNotNull($added);
        $this->assertSame($dana->id, $added->added_by);
        $this->assertStringContainsString('Added "Bon Secours Mary Immaculate" to Facilities', AuditLog::latest('id')->first()->description);
        $this->assertSame(3, $facilities->items()->count());

        // With adding turned off, only listed entries are accepted.
        $facilities->update(['allow_add' => false]);
        $this->actingAs($dana)->get("/forms/{$form->id}")->assertSee('<select', false);
        $this->actingAs($dana)->post("/forms/{$form->id}", ["f{$where->id}" => 'Somewhere New'])->assertSessionHasErrors("f{$where->id}");
        $this->assertSame(3, $facilities->items()->count());
    }

    public function test_admins_manage_shared_lists_and_every_change_is_audited(): void
    {
        $admin = User::factory()->role('admin')->create(['name' => 'Ada Admin']);
        $dana = $this->dispatcherWithForms();
        $this->actingAs($dana)->get('/admin/lists')->assertForbidden();
        $this->actingAs($admin)->get('/admin/lists')->assertOk()->assertSee('Facilities');

        $facilities = \App\Models\OptionList::where('name', 'Facilities')->first();
        $label = fn () => AuditLog::latest('id')->first()->label();

        $this->actingAs($admin)->post("/admin/lists/{$facilities->id}/items", ['names' => "Riverside Regional\nSentara Norfolk\n riverside regional \n\n"])->assertSessionHasNoErrors();
        $this->assertSame(['Riverside Regional', 'Sentara Norfolk'], $facilities->items()->pluck('name')->all());
        $this->assertSame('Added to a shared list', $label());
        $this->assertStringContainsString('Riverside Regional, Sentara Norfolk', AuditLog::latest('id')->first()->description);

        $item = $facilities->items()->where('name', 'Sentara Norfolk')->first();
        $this->actingAs($admin)->put("/admin/list-items/{$item->id}", ['name' => 'Riverside regional'])->assertSessionHas('error');
        $this->actingAs($admin)->put("/admin/list-items/{$item->id}", ['name' => 'Sentara Norfolk General'])->assertSessionHas('status');
        $this->assertSame('Sentara Norfolk General', $item->fresh()->name);
        $this->assertSame('Renamed a shared list entry', $label());

        $this->actingAs($admin)->post("/admin/list-items/{$item->id}/toggle");
        $this->assertFalse($item->fresh()->active);
        $this->assertSame('Hid or unhid a shared list entry', $label());

        $this->actingAs($admin)->put("/admin/lists/{$facilities->id}", ['name' => 'Facilities', 'allow_add' => '0']);
        $this->assertFalse($facilities->fresh()->allow_add);
        $this->assertSame('Changed a shared list', $label());

        $this->actingAs($admin)->delete("/admin/list-items/{$item->id}");
        $this->assertNull($item->fresh());
        $this->assertSame('Removed a shared list entry', $label());

        $this->actingAs($admin)->post('/admin/lists', ['name' => 'Destinations'])->assertRedirect();
        $this->assertSame('Added a shared list', $label());
        $dest = \App\Models\OptionList::where('name', 'Destinations')->first();

        // A list a question uses can't be deleted.
        $form = Form::create(['name' => 'Transfers', 'active' => true]);
        $form->fields()->create(['label' => 'To', 'type' => 'list', 'option_list_id' => $dest->id, 'position' => 1]);
        $this->actingAs($admin)->delete("/admin/lists/{$dest->id}")->assertSessionHas('error');
        $this->assertNotNull($dest->fresh());
        $form->fields()->delete();
        $this->actingAs($admin)->delete("/admin/lists/{$dest->id}");
        $this->assertNull($dest->fresh());
        $this->assertSame('Deleted a shared list', $label());
    }

    public function test_form_builder_adds_a_shared_list_question(): void
    {
        $admin = User::factory()->role('admin')->create();
        $form = Form::create(['name' => 'Missed calls', 'active' => true]);
        $this->actingAs($admin)->get("/admin/forms/{$form->id}")->assertOk()->assertSee('Shared list');
        $this->actingAs($admin)->post("/admin/forms/{$form->id}/fields", ['label' => 'Where', 'type' => 'list'])->assertSessionHasNoErrors();
        $field = $form->fields()->first();
        $this->assertSame('list', $field->type);
        $this->assertSame('Facilities', $field->optionList->name);
    }
}
