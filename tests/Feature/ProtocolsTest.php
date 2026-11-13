<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PermissionProfile;
use App\Models\Protocol;
use App\Models\ProtocolCategory;
use App\Models\ProtocolVersion;
use App\Models\User;
use App\Support\ProtocolChart;
use Database\Seeders\ProtocolSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtocolsTest extends TestCase
{
    use RefreshDatabase;

    private function grant(string $profile, array $permissions): void
    {
        $p = PermissionProfile::where('name', $profile)->first();
        $p->update(['permissions' => array_values(array_unique([...$p->permissions, ...$permissions]))]);
    }

    private function builder(): User
    {
        $this->grant('Supervisor', ['protocols', 'protocols_build']);

        return User::factory()->role('supervisor')->create(['name' => 'Sam Builder']);
    }

    private function medicalDirector(): User
    {
        $profile = PermissionProfile::create(['name' => 'Medical Director', 'permissions' => ['protocols', 'protocols_approve']]);

        return User::factory()->create(['name' => 'Dr. Morgan', 'permission_profile_id' => $profile->id]);
    }

    private function teamMember(): User
    {
        $this->grant('Team Member', ['protocols']);

        return User::factory()->role('employee')->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'number' => '4.1',
            'title' => 'Cardiac Arrest',
            'protocol_category_id' => ProtocolCategory::where('name', 'Cardiac Arrest')->value('id'),
            'ages' => ['adult', 'pediatric'],
            'revised_label' => 'Revised 2020',
            'chart' => json_encode(['nodes' => [
                ['id' => 'n1', 'type' => 'decision', 'text' => 'Pulse?', 'badges' => []],
                ['id' => 'n2', 'type' => 'action', 'text' => "**High Quality CPR**\nas soon as possible", 'badges' => []],
                ['id' => 'n3', 'type' => 'step', 'text' => "**Epinephrine** 1 mg IV/IO", 'badges' => ['peds', 'P', 'A', 'bogus']],
            ], 'edges' => [
                ['from' => 'n1', 'to' => 'n2', 'label' => 'NO', 'style' => 'one'],
                ['from' => 'n2', 'to' => 'n3', 'label' => 'If no response in 30 sec', 'style' => 'both'],
            ]]),
            'sections' => json_encode([
                ['heading' => 'Goals', 'color' => 'purple', 'badges' => [], 'text' => "- Early recognition\n- High quality CPR"],
                ['heading' => 'Pediatrics', 'color' => 'pink', 'badges' => ['peds'], 'text' => '__**Torsades**__: Magnesium sulfate 50 mg/kg'],
                ['heading' => 'Empty', 'color' => 'red', 'badges' => [], 'text' => '  '],
            ]),
        ], $overrides);
    }

    public function test_new_permissions_start_with_admin_only(): void
    {
        $admin = PermissionProfile::where('name', 'Admin')->first();
        foreach (['protocols', 'protocols_build', 'protocols_approve'] as $permission) {
            $this->assertTrue($admin->allows($permission));
            foreach (['Team Member', 'Dispatcher', 'Supervisor'] as $name) {
                $this->assertFalse(PermissionProfile::where('name', $name)->first()->allows($permission), "$name has $permission");
            }
        }
        $this->actingAs(User::factory()->role('employee')->create())->get('/protocols')->assertForbidden();
        $this->actingAs(User::factory()->role('supervisor')->create())->get('/protocols/manage')->assertForbidden();
        $this->actingAs(User::factory()->role('admin')->create())->get('/admin/profiles')->assertOk()->assertSee('Medical Director (approve');
    }

    public function test_draft_is_hidden_until_the_medical_director_approves_it(): void
    {
        $builder = $this->builder();
        $md = $this->medicalDirector();
        $tm = $this->teamMember();

        $this->actingAs($builder)->post('/protocols/manage', $this->payload())->assertRedirect('/protocols/manage/1');
        $v = ProtocolVersion::first();
        $this->assertSame('draft', $v->status);
        $this->assertSame(['A', 'P', 'peds'], $v->boxes()[2]['badges'], 'Unknown badges dropped, usual order kept');
        $this->assertSame('If no response in 30 sec', $v->arrows()[1]['label']);
        $this->assertSame(['Goals', 'Pediatrics'], collect($v->sections)->pluck('heading')->all(), 'Empty section left off');
        $this->assertSame('Cardiac Arrest', $v->category_name);
        $this->assertStringContainsString('Epinephrine 1 mg IV/IO', $v->search_text);
        $this->assertStringContainsString('Started protocol 4.1 Cardiac Arrest', AuditLog::latest('id')->first()->description);

        // Not in the book yet.
        $this->actingAs($tm)->get('/protocols')->assertOk()->assertDontSee(route('protocols.show', 1).'"', false)->assertSee('No protocols have been approved yet.');
        $this->actingAs($tm)->get('/protocols/1')->assertNotFound();
        $this->actingAs($tm)->get('/protocols/versions/'.$v->id)->assertForbidden();

        $this->actingAs($builder)->post('/protocols/manage/1/submit')->assertSessionHas('status');
        $this->assertSame('submitted', $v->fresh()->status);
        // Locked while it waits.
        $this->actingAs($builder)->put('/protocols/manage/1', $this->payload(['title' => 'Changed']))->assertSessionHas('error');
        $this->actingAs($builder)->post('/protocols/approvals/'.$v->id.'/approve', ['signature' => 'x', 'confirm' => 1])->assertForbidden();

        $this->actingAs($md)->get('/protocols/approvals')->assertOk()->assertSee('4.1 Cardiac Arrest');
        $this->actingAs($md)->get('/protocols/approvals/'.$v->id)->assertOk()->assertSee('Approve and sign');
        $this->actingAs($md)->post('/protocols/approvals/'.$v->id.'/approve', ['signature' => 'Dr. Morgan'])->assertSessionHasErrors('confirm');
        $this->actingAs($md)->post('/protocols/approvals/'.$v->id.'/approve', ['signature' => 'Dr. Morgan', 'confirm' => 1])->assertRedirect('/protocols/approvals');

        $v->refresh();
        $this->assertSame('approved', $v->status);
        $this->assertSame('Dr. Morgan', $v->signature);
        $this->assertSame($md->id, $v->reviewed_by);
        $this->assertSame($v->id, Protocol::first()->approved_version_id);
        $log = AuditLog::latest('id')->first();
        $this->assertSame('protocols.approvals.approve', $log->action);
        $this->assertStringContainsString('In the book from not in the book to version 1', $log->description);

        $this->actingAs($tm)->get('/protocols/1')->assertOk()
            ->assertSee('Cardiac Arrest')
            ->assertSee('Approved by Dr. Morgan')
            ->assertSee('Open chart full screen')
            ->assertSee('<strong>Torsades</strong>', false)
            ->assertSee('<li>Early recognition</li>', false);
    }

    public function test_a_revision_keeps_the_approved_version_in_the_book(): void
    {
        $builder = $this->builder();
        $md = $this->medicalDirector();
        $tm = $this->teamMember();
        $this->actingAs($builder)->post('/protocols/manage', $this->payload());
        $this->actingAs($builder)->post('/protocols/manage/1/submit');
        $this->actingAs($md)->post('/protocols/approvals/1/approve', ['signature' => 'Dr. Morgan', 'confirm' => 1]);

        $this->actingAs($builder)->put('/protocols/manage/1', $this->payload())->assertSessionHas('error', 'Start a revision before making changes.');
        $this->actingAs($builder)->post('/protocols/manage/1/revise')->assertSessionHas('status');
        $this->actingAs($builder)->put('/protocols/manage/1', $this->payload(['title' => 'Cardiac Arrest (Adult)']))->assertSessionHas('status');
        $this->assertStringContainsString('title from Cardiac Arrest to Cardiac Arrest (Adult)', AuditLog::latest('id')->first()->description);

        // The book still shows version 1, unchanged.
        $this->actingAs($tm)->get('/protocols/1')->assertOk()->assertSee('protocol-title">Cardiac Arrest<', false);
        $this->assertSame('Cardiac Arrest', ProtocolVersion::find(1)->title);

        $this->actingAs($builder)->post('/protocols/manage/1/submit');
        $this->actingAs($md)->post('/protocols/approvals/2/return', ['review_note' => 'Keep the old title.']);
        $this->assertSame('returned', ProtocolVersion::find(2)->status);
        $this->actingAs($builder)->get('/protocols/manage/1')->assertSee('Keep the old title.');

        $this->actingAs($builder)->post('/protocols/manage/1/submit');
        $this->actingAs($md)->post('/protocols/approvals/2/approve', ['signature' => 'Dr. Morgan', 'confirm' => 1]);
        $this->assertSame('superseded', ProtocolVersion::find(1)->status);
        $this->actingAs($tm)->get('/protocols/1')->assertSee('protocol-title">Cardiac Arrest (Adult)<', false);

        // Retiring takes it out of the book.
        $this->actingAs($md)->post('/protocols/1/retire');
        $this->actingAs($tm)->get('/protocols/1')->assertNotFound();
    }

    public function test_book_filters_by_category_and_age_and_searches(): void
    {
        $builder = $this->builder();
        $md = $this->medicalDirector();
        $tm = $this->teamMember();
        $resp = ProtocolCategory::where('name', 'Respiratory')->value('id');
        $this->actingAs($builder)->post('/protocols/manage', $this->payload());
        $this->actingAs($builder)->post('/protocols/manage', $this->payload([
            'number' => '2.3', 'title' => 'Asthma', 'protocol_category_id' => $resp, 'ages' => ['infant'],
            'chart' => json_encode(['nodes' => [['id' => 'n1', 'type' => 'step', 'text' => 'Albuterol 2.5 mg nebulized']], 'edges' => []]),
        ]));
        foreach ([1, 2] as $id) {
            $this->actingAs($builder)->post("/protocols/manage/$id/submit");
            $this->actingAs($md)->post("/protocols/approvals/$id/approve", ['signature' => 'Dr. Morgan', 'confirm' => 1]);
        }

        $this->actingAs($tm)->get('/protocols?q=albuterol')->assertSee('Asthma')->assertDontSee(route('protocols.show', 1).'"', false)->assertSee('1 protocol found');
        $this->actingAs($tm)->get('/protocols?q=epinephrine%20iv')->assertSee(route('protocols.show', 1).'"', false)->assertDontSee(route('protocols.show', 2).'"', false);
        $this->actingAs($tm)->get('/protocols?age=infant')->assertSee('Asthma')->assertDontSee(route('protocols.show', 1).'"', false);
        $this->actingAs($tm)->get('/protocols/book?category='.$resp)->assertOk()->assertSee('Asthma')->assertDontSee('High Quality CPR');
        $this->actingAs($tm)->get('/protocols/book')->assertOk()->assertSee('Asthma')->assertSee('Cardiac Arrest');
    }

    public function test_exit_boxes_link_only_to_protocols_in_the_book(): void
    {
        $builder = $this->builder();
        $md = $this->medicalDirector();
        $this->actingAs($builder)->post('/protocols/manage', $this->payload(['title' => 'ROSC', 'number' => '4.2']));
        $chart = ['nodes' => [['id' => 'n1', 'type' => 'exit', 'text' => '**EXIT** to ROSC Protocol', 'link' => 1]], 'edges' => []];
        $this->actingAs($builder)->post('/protocols/manage', $this->payload(['chart' => json_encode($chart)]));
        $arrest = ProtocolVersion::find(2);
        $this->assertSame(1, $arrest->boxes()[0]['link']);

        $source = fn () => ProtocolChart::mermaid($arrest->chart, \App\Support\ProtocolLinks::forBook());
        $this->assertStringNotContainsString('href', $source(), 'ROSC is not approved yet');
        $this->actingAs($builder)->post('/protocols/manage/1/submit');
        $this->actingAs($md)->post('/protocols/approvals/1/approve', ['signature' => 'Dr. Morgan', 'confirm' => 1]);
        $this->assertStringContainsString("href='".route('protocols.show', 1)."'", $source());
        $this->assertSame(1, $arrest->toApi()['chart']['boxes'][0]['links_to']);
    }

    public function test_box_text_is_escaped_in_the_chart(): void
    {
        $chart = ProtocolChart::clean(['nodes' => [['id' => 'n1', 'type' => 'step', 'text' => '<script>alert(1)</script> "quoted" **bold**']], 'edges' => []]);
        $source = ProtocolChart::mermaid($chart, fn () => null);
        $this->assertStringNotContainsString('<script>', $source);
        $this->assertStringContainsString('&lt;script&gt;', $source);
        $this->assertStringContainsString('<strong>bold</strong>', $source);
        $this->assertStringNotContainsString('"quoted"', $source);
    }

    public function test_bad_charts_are_refused(): void
    {
        $builder = $this->builder();
        $bad = json_encode(['nodes' => [['id' => 'n1', 'type' => 'step', 'text' => 'A']], 'edges' => [['from' => 'n1', 'to' => 'n9']]]);
        $this->actingAs($builder)->post('/protocols/manage', $this->payload(['chart' => $bad]))->assertSessionHasErrors('chart');
        $empty = json_encode(['nodes' => [['id' => 'n1', 'type' => 'step', 'text' => ' ']], 'edges' => []]);
        $this->actingAs($builder)->post('/protocols/manage', $this->payload(['chart' => $empty]))->assertSessionHasErrors('chart');
        $this->assertSame(0, Protocol::count());
    }

    public function test_preview_renders_without_saving_or_logging(): void
    {
        $builder = $this->builder();
        $before = AuditLog::count();
        $this->actingAs($builder)->post('/protocols/manage/preview', $this->payload())->assertOk()->assertSee('High Quality CPR');
        $this->assertSame(0, Protocol::count());
        $this->assertSame($before, AuditLog::count());
    }

    public function test_discarding_a_never_approved_draft_removes_the_protocol(): void
    {
        $builder = $this->builder();
        $this->actingAs($builder)->post('/protocols/manage', $this->payload());
        $this->actingAs($builder)->delete('/protocols/manage/1/draft')->assertRedirect('/protocols/manage');
        $this->assertSame(0, Protocol::count());
        $this->assertSame('protocols.manage.discard', AuditLog::latest('id')->first()->action);
    }

    public function test_categories_and_sample_seeder(): void
    {
        $builder = $this->builder();
        $this->actingAs($builder)->post('/protocols/categories', ['name' => 'Trauma'])->assertSessionHas('status');
        $trauma = ProtocolCategory::where('name', 'Trauma')->first();
        $this->actingAs($builder)->put('/protocols/categories/'.$trauma->id, ['name' => 'Trauma & Burns', 'position' => 9]);
        $this->assertStringContainsString('name from Trauma to Trauma & Burns', AuditLog::latest('id')->first()->description);

        $this->seed(ProtocolSampleSeeder::class);
        $v = ProtocolVersion::first();
        $this->assertSame('draft', $v->status);
        $this->assertCount(28, $v->boxes());
        $this->assertSame($v->chart, ProtocolChart::clean($v->chart), 'The sample is valid builder data');
        $this->actingAs($builder)->get('/protocols/manage/1')->assertOk()->assertSee('Send for approval');
    }
}
