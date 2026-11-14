<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EmsAffiliation;
use App\Models\EmsCheck;
use App\Models\EmsUnacceptableAgency;
use App\Models\User;
use App\Notifications\EmsCheckNeedsAttention;
use App\Services\Ems\Affiliations;
use App\Services\Ems\Agency;
use App\Services\Ems\ProviderSearch;
use App\Services\Ems\SearchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmsAffiliationsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<array>> "first last" or "#cert" => providers */
    private array $site = [];

    /** @var list<string> */
    private array $searches = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.va_ems.pause_ms' => 0]);
        $test = $this;
        $this->app->instance(ProviderSearch::class, new class($test) extends ProviderSearch
        {
            public function __construct(private $test) {}

            public function search(?string $first, ?string $last, ?string $cert = null): array
            {
                return $this->test->answer($first, $last, $cert);
            }
        });
    }

    public function answer(?string $first, ?string $last, ?string $cert): array
    {
        $key = $cert ? "#{$cert}" : strtoupper("{$first} {$last}");
        $this->searches[] = $key;
        if (($this->site[$key] ?? null) === 'fail') {
            throw new SearchFailed('The provider search answered with error 503.');
        }

        return $this->site[$key] ?? [];
    }

    private function provider(string $name, string $cert, array $agencies): array
    {
        return ['name' => $name, 'first' => null, 'last' => null, 'cert' => $cert, 'level' => 'EMT', 'expires' => '12/31/2027', 'agencies' => $agencies];
    }

    public function test_a_check_finds_agencies_flags_and_keeps_history(): void
    {
        Notification::fake();
        $admin = User::factory()->role('admin')->create(['name' => 'Ada Admin']);
        $john = User::factory()->create(['name' => 'John Smith']);
        $mary = User::factory()->create(['name' => 'Mary Jones']);
        $pat = User::factory()->create(['name' => 'Pat Crew']);
        $dana = User::factory()->create(['name' => 'Dana Dispatch']);
        $dana->forceFill(['ems_exclusion_at' => now(), 'ems_exclusion_note' => 'Dispatcher'])->save();
        $lee = User::factory()->create(['name' => 'Lee Office']);
        $lee->forceFill(['ems_exclusion_at' => now(), 'ems_exclusion_note' => 'Office'])->save();
        EmsUnacceptableAgency::create(['name' => 'Rival Ambulance Inc', 'agency_key' => Agency::key('Rival Ambulance Inc')]);

        $this->site = [
            'JOHN SMITH' => [
                $this->provider('SMITH, JOHN A', '123456', ['FAST TRACK EMS, LLC', 'CITY OF NORFOLK FIRE']),
                $this->provider('SMITH, JOHN', '777777', ['SOMEWHERE RESCUE SQUAD']), // a different John Smith
            ],
            'MARY JONES' => [$this->provider('JONES, MARY', '222', ['RIVAL AMBULANCE, INC.'])],
            'LEE OFFICE' => [$this->provider('OFFICE, LEE', '333', ['Fast Track EMS LLC'])],
        ];

        $this->actingAs($admin)->post('/admin/ems-affiliations')->assertSessionHas('status');
        $this->assertSame('queued', EmsCheck::sole()->status);
        $this->artisan('ems:run')->assertSuccessful();

        $check = EmsCheck::sole();
        $this->assertSame('done', $check->status);
        $this->assertSame(6, $check->members_total);
        $results = $check->results->keyBy('user_id');
        $this->assertSame('found', $results[$john->id]->status);
        $this->assertTrue($results[$john->id]->has_ours);
        $this->assertSame(['CITY OF NORFOLK FIRE'], $results[$john->id]->new_agencies);
        $this->assertSame('123456', $john->fresh()->ems_cert); // picked the one listed with us, and saved the number
        $this->assertFalse($results[$mary->id]->has_ours);
        $this->assertSame(['RIVAL AMBULANCE, INC.'], $results[$mary->id]->unacceptable_agencies);
        $this->assertNull($mary->fresh()->ems_cert); // not listed with us: not saved
        $this->assertSame('not_found', $results[$pat->id]->status);
        $this->assertTrue($results[$dana->id]->accepted_exclusion);
        $this->assertTrue($results[$lee->id]->exclusion_found);
        $this->assertNotNull($lee->fresh()->ems_exclusion_found_at);

        $this->assertSame(1, $check->review_count);
        $this->assertSame(1, $check->unacceptable_count);
        $this->assertSame(1, $check->missing_ours_count);
        $this->assertSame(2, $check->not_found_count); // Pat, and Ada Admin
        $this->assertSame(1, $check->excluded_count);

        $norfolk = EmsAffiliation::where('user_id', $john->id)->sole();
        $this->assertSame('pending', $norfolk->status);
        $rival = EmsAffiliation::where('user_id', $mary->id)->sole();
        $this->assertSame('unacceptable', $rival->status);
        $this->assertTrue($rival->auto_flagged);

        Notification::assertSentTo($admin, EmsCheckNeedsAttention::class, fn ($n) => collect($n->lines)->contains(fn ($l) => str_contains($l, 'Lee Office')));
        Notification::assertNotSentTo($john, EmsCheckNeedsAttention::class);
        $this->assertTrue(AuditLog::where('action', 'system.ems-check')->where('description', 'like', '%123456%')->exists());

        $this->actingAs($admin)->get('/admin/ems-affiliations')
            ->assertSee('Needs review')->assertSee('CITY OF NORFOLK FIRE')->assertSee('RIVAL AMBULANCE, INC.')->assertSee('Lee Office')
            ->assertSee('1 unacceptable affiliation')->assertSee('1 not listed with us');
        $this->actingAs($admin)->get("/admin/ems-affiliations/{$check->id}")
            ->assertSee('John Smith')->assertSee('Listed with us')->assertSee('Not listed with us')->assertSee('Not found (accepted exclusion)')
            ->assertSee('Marked accepted exclusion, but found on the search.');

        // Next month: searched by number; Norfolk is gone; the exclusion isn't announced twice.
        Notification::fake();
        $this->site['#123456'] = [$this->provider('SMITH, JOHN A', '123456', ['FAST TRACK EMS, LLC'])];
        $this->searches = [];
        $this->artisan('ems:check')->assertSuccessful();
        $this->assertContains('#123456', $this->searches);
        $this->assertNotNull($norfolk->fresh()->gone_at);
        Notification::assertSentTo($admin, EmsCheckNeedsAttention::class, fn ($n) => ! collect($n->lines)->contains(fn ($l) => str_contains($l, 'Lee Office')));
        $this->assertNull(EmsCheck::latest('id')->first()->run_by);

        $this->actingAs($admin)->get("/admin/ems-affiliations/members/{$john->id}")
            ->assertSee('CITY OF NORFOLK FIRE')->assertSee('No longer listed')->assertSee('123456');
    }

    public function test_reviews_and_the_unacceptable_list(): void
    {
        $admin = User::factory()->role('admin')->create();
        $john = User::factory()->create(['name' => 'John Smith']);
        $mary = User::factory()->create(['name' => 'Mary Jones']);
        $seen = ['first_seen_at' => now(), 'last_seen_at' => now()];
        $a = EmsAffiliation::create(['user_id' => $john->id, 'agency' => 'BAD MEDICS LLC', 'agency_key' => Agency::key('BAD MEDICS LLC')] + $seen);
        $b = EmsAffiliation::create(['user_id' => $mary->id, 'agency' => 'Bad Medics', 'agency_key' => Agency::key('Bad Medics')] + $seen);
        $c = EmsAffiliation::create(['user_id' => $mary->id, 'agency' => 'Good Rescue Squad', 'agency_key' => Agency::key('Good Rescue Squad')] + $seen);

        $this->actingAs($admin)->post("/admin/ems-affiliations/affiliations/{$c->id}/review", ['status' => 'acceptable', 'note' => 'Volunteer'])->assertSessionHas('status');
        $this->assertSame('acceptable', $c->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'admin.ems.review')->where('description', 'like', '%from To review to Acceptable%')->exists());

        // Unacceptable for John, and for everyone: Mary's waiting one is flagged too.
        $this->actingAs($admin)->post("/admin/ems-affiliations/affiliations/{$a->id}/review", ['status' => 'unacceptable', 'everyone' => '1']);
        $this->assertSame('unacceptable', $a->fresh()->status);
        $this->assertFalse($a->fresh()->auto_flagged);
        $this->assertSame('unacceptable', $b->fresh()->status);
        $this->assertTrue($b->fresh()->auto_flagged);
        $agency = EmsUnacceptableAgency::sole();

        $this->actingAs($admin)->post('/admin/ems-affiliations/unacceptable', ['name' => 'Bad Medics, LLC'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/admin/ems-affiliations/unacceptable', ['name' => 'Fast Track EMS'])->assertSessionHasErrors(['name' => "That's our agency."]);

        // Off the list: only the one flagged by the list goes back to review.
        $this->actingAs($admin)->delete("/admin/ems-affiliations/unacceptable/{$agency->id}")->assertSessionHas('status');
        $this->assertSame('pending', $b->fresh()->status);
        $this->assertSame('unacceptable', $a->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'admin.ems.unacceptable.destroy')->where('description', 'like', '%1 affiliation went back to review%')->exists());
    }

    public function test_certification_number_exclusion_and_permission(): void
    {
        $admin = User::factory()->role('admin')->create();
        $member = User::factory()->create(['name' => 'Pat Crew']);

        $this->actingAs($admin)->put("/admin/ems-affiliations/members/{$member->id}/cert", ['cert' => 'a12-34'])->assertSessionHas('status');
        $this->assertSame('A12-34', $member->fresh()->ems_cert);
        $this->assertTrue(AuditLog::where('action', 'admin.ems.member.cert')->where('description', 'like', '%from none to A12-34%')->exists());

        $this->actingAs($admin)->put("/admin/ems-affiliations/members/{$member->id}/exclusion", ['excluded' => '1'])->assertSessionHasErrors('note');
        $this->actingAs($admin)->put("/admin/ems-affiliations/members/{$member->id}/exclusion", ['excluded' => '1', 'note' => 'Not certified'])->assertSessionHas('status');
        $this->assertNotNull($member->fresh()->ems_exclusion_at);
        $this->actingAs($admin)->get("/admin/ems-affiliations/members/{$member->id}")->assertSee('Not certified')->assertSee('Remove the exclusion');
        $this->actingAs($admin)->put("/admin/ems-affiliations/members/{$member->id}/exclusion", ['excluded' => '0']);
        $this->assertNull($member->fresh()->ems_exclusion_at);
        $this->assertTrue(AuditLog::where('action', 'admin.ems.member.exclusion')->where('description', 'like', 'Removed Pat Crew%was: Not certified%')->exists());

        $supervisor = User::factory()->role('supervisor')->create();
        $this->actingAs($supervisor)->get('/admin/ems-affiliations')->assertForbidden();
        $this->actingAs($supervisor)->post('/admin/ems-affiliations')->assertForbidden();
    }

    public function test_more_than_one_match_and_a_site_that_is_down(): void
    {
        Notification::fake();
        User::factory()->role('admin')->create(['name' => 'Ada Admin']);
        $john = User::factory()->create(['name' => 'John Smith']);
        $this->site['JOHN SMITH'] = [
            $this->provider('SMITH, JOHN', '1', ['A RESCUE']),
            $this->provider('SMITH, JOHN', '2', ['B RESCUE']),
        ];
        $check = app(Affiliations::class)->run(app(Affiliations::class)->queue(null));
        $result = $check->results()->where('user_id', $john->id)->sole();
        $this->assertSame('multiple', $result->status);
        $this->assertCount(2, $result->providers);
        $this->assertSame(0, EmsAffiliation::count()); // not saved against the wrong person

        // Everyone fails: the check stops after three.
        foreach (['ADA ADMIN', 'JOHN SMITH', 'PAT A', 'PAT B'] as $name) {
            $this->site[$name] = 'fail';
        }
        User::factory()->create(['name' => 'Pat A']);
        User::factory()->create(['name' => 'Pat B']);
        $this->searches = [];
        $check = app(Affiliations::class)->run(app(Affiliations::class)->queue(null));
        $this->assertSame('failed', $check->status);
        $this->assertSame('The provider search answered with error 503.', $check->error);
        $this->assertCount(3, $this->searches);
    }

    public function test_the_apex_page_is_filled_in_and_the_results_read(): void
    {
        $this->app->forgetInstance(ProviderSearch::class);
        $page = <<<'HTML'
            <html><body><form action="wwv_flow.accept?p_context=200:3:987" method="post" id="wwvFlowForm">
            <input type="hidden" name="p_flow_id" value="200"><input type="hidden" name="p_flow_step_id" value="3">
            <input type="hidden" name="p_instance" value="987"><input type="hidden" name="p_page_submission_id" value="SUB1">
            <input type="hidden" name="p_request" value=""><input type="hidden" name="p_reload_on_submit" value="S">
            <input type="hidden" name="pSalt" id="pSalt" value="SALT1"><input type="hidden" name="pPageItemsProtected" id="pPageItemsProtected" value="PROT1">
            <label for="P3_CERT">Certification#</label><input type="text" id="P3_CERT" name="P3_CERT" value="">
            <label for="P3_LNAME">Last Name</label><input type="text" id="P3_LNAME" name="P3_LNAME" value="">
            <label for="P3_FNAME">First Name</label><input type="text" id="P3_FNAME" name="P3_FNAME" value="">
            <label for="P3_AGENCY">Agency</label><input type="text" id="P3_AGENCY" name="P3_AGENCY" value="">
            <button type="button" id="B1" onclick="apex.submit({request:'GO_SEARCH',validate:true});"><span>Search</span></button>
            <button type="button" id="B2" onclick="apex.submit('RESET');">Reset</button>
            </form></body></html>
            HTML;
        $results = <<<'HTML'
            <table class="t-Report-report"><thead><tr><th id="NAME">Name</th><th id="CERT">Certification#</th><th>Level</th><th>Expiration Date</th><th>Agency</th></tr></thead>
            <tbody>
            <tr><td headers="NAME">SMITH, JOHN A</td><td>123456</td><td>EMT</td><td>12/31/2027</td><td>FAST TRACK EMS, LLC<br>CITY OF NORFOLK FIRE</td></tr>
            <tr><td></td><td></td><td></td><td></td><td>SOMEWHERE RESCUE SQUAD</td></tr>
            <tr><td>SMITH, JOHNNY</td><td>999</td><td>ALS</td><td>01/01/2028</td><td>OTHER EMS</td></tr>
            </tbody></table>
            HTML;
        Http::fake([
            'vdhems.vdh.virginia.gov/emsapps/ProviderSearch.html' => Http::response($page),
            'vdhems.vdh.virginia.gov/emsapps/wwv_flow.accept*' => Http::response(['redirectURL' => 'f?p=200:3:987::NO:::']),
            'vdhems.vdh.virginia.gov/emsapps/f*' => Http::response($results),
        ]);

        $search = app(ProviderSearch::class);
        $providers = $search->search('John', 'Smith');

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'wwv_flow.accept')) {
                return false;
            }
            $json = json_decode($request['p_json'], true);
            $items = collect($json['pageItems']['itemsToSubmit'])->pluck('v', 'n');

            return $request['p_request'] === 'GO_SEARCH' && $request['p_instance'] === '987' && $json['salt'] === 'SALT1'
                && $json['pageItems']['protected'] === 'PROT1' && $items['P3_LNAME'] === 'Smith' && $items['P3_FNAME'] === 'John' && $items['P3_CERT'] === '';
        });
        $this->assertCount(2, $providers);
        $this->assertSame('123456', $providers[0]['cert']);
        $this->assertSame('EMT', $providers[0]['level']);
        $this->assertSame(['FAST TRACK EMS, LLC', 'CITY OF NORFOLK FIRE', 'SOMEWHERE RESCUE SQUAD'], $providers[0]['agencies']);
        $this->assertSame(['OTHER EMS'], $providers[1]['agencies']);
        $this->assertSame(['cert', 'last', 'first', 'agency'], $search->trace['boxes']);

        // "Try the search" shows it without saving anything.
        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin)->post('/admin/ems-affiliations/test', ['first' => 'John', 'last' => 'Smith'])->assertSessionHas('ems_test');
        $this->actingAs($admin)->get('/admin/ems-affiliations')->assertSee('2 found')->assertSee('CITY OF NORFOLK FIRE');
        $this->assertTrue(AuditLog::where('action', 'admin.ems.test')->where('description', 'like', '%John Smith: 2 found%')->exists());
        $this->assertSame(0, EmsCheck::count());
    }

    public function test_the_results_region_is_refreshed_like_the_live_site(): void
    {
        // As the live page works (Nov 2026): Search refreshes an interactive
        // report through wwv_flow.ajax instead of submitting the page.
        $this->app->forgetInstance(ProviderSearch::class);
        $ajax = 'UkVHSU9OIFRZUEV-fjUwOTU4NTIxNTc1MjcwODM4/rvF3QuCVtN1eqipoHXVYcwg9d7n8kp1ooIb_H24nxaVjB5RuBZwkSd8l3oZ0-vEBuJEjrze7guV_1u1Ycx5N_Q';
        $page = '<html><body><form action="wwv_flow.accept?p_context=200:3:33415634033990" method="post" id="wwvFlowForm">'
            .'<input type="hidden" name="p_flow_id" value="200"><input type="hidden" name="p_flow_step_id" value="3">'
            .'<input type="hidden" name="p_instance" value="33415634033990"><input type="hidden" name="p_page_submission_id" value="S1">'
            .'<input type="hidden" name="pSalt" id="pSalt" value="31671285852698723669491300224693512352">'
            .'<input type="hidden" name="pPageItemsProtected" id="pPageItemsProtected" value="PROT">'
            .'<label for="P3_CERT_NO">Certification#</label><input type="text" id="P3_CERT_NO" name="P3_CERT_NO">'
            .'<label for="P3_LAST_NAME">Last Name</label><input type="text" id="P3_LAST_NAME" name="P3_LAST_NAME">'
            .'<label for="P3_FIRST_NAME">First Name</label><input type="text" id="P3_FIRST_NAME" name="P3_FIRST_NAME">'
            .'<label for="P3_AGENCY_LIST">Agency</label><select id="P3_AGENCY_LIST" name="P3_AGENCY_LIST"><option value="ALL" selected>- All -</option><option value="1">X</option></select>'
            .'<button type="button" id="search">Search</button>'
            .'<table><tr><td>No data found.</td></tr></table>'
            .'</form><script>apex.widget.interactiveReport("R50958521575270838_ir",{"regionId":"R50958521575270838","ajaxIdentifier":"'.str_replace('/', '\/', $ajax).'"});</script></body></html>';
        $region = '<div class="a-IRR"><table class="a-IRR-table"><tr><th class="a-IRR-header" id="C1">Name</th><th class="a-IRR-header" id="C2">Cert#</th><th id="C3">Level</th><th id="C4">Expiration Date</th><th id="C5">Affiliated Agency</th></tr>'
            .'<tr><td headers="C1">SMITH, JOHN</td><td headers="C2">E022081013</td><td>EMT</td><td>06/30/2028</td><td>FAST TRACK EMS, LLC</td></tr></table></div>';
        Http::fake([
            'vdhems.vdh.virginia.gov/emsapps/ProviderSearch.html' => Http::response($page),
            'vdhems.vdh.virginia.gov/emsapps/wwv_flow.ajax*' => Http::sequence()
                ->push($region)
                ->push('<div class="a-IRR"><span class="nodatafound">No data found.</span></div>'),
        ]);

        $search = app(ProviderSearch::class);
        $providers = $search->search(null, null, 'E022081013');

        Http::assertSent(function (Request $request) use ($ajax) {
            if (! str_contains($request->url(), 'wwv_flow.ajax?p_context=200:3:33415634033990')) {
                return false;
            }
            $json = json_decode($request['p_json'], true);

            return $request['p_request'] === 'PLUGIN='.$ajax && $request['x01'] === '50958521575270838' && $request['p_widget_action'] === 'reset'
                && $request->hasHeader('X-Requested-With', 'XMLHttpRequest')
                && $json['pageItems']['itemsToSubmit'] === [
                    ['n' => 'P3_CERT_NO', 'v' => 'E022081013'], ['n' => 'P3_LAST_NAME', 'v' => ''],
                    ['n' => 'P3_FIRST_NAME', 'v' => ''], ['n' => 'P3_AGENCY_LIST', 'v' => 'ALL'],
                ]
                && $json['pageItems']['protected'] === 'PROT' && $json['salt'] === '31671285852698723669491300224693512352';
        });
        $this->assertCount(1, $providers);
        $this->assertSame('E022081013', $providers[0]['cert']);
        $this->assertSame(['FAST TRACK EMS, LLC'], $providers[0]['agencies']);

        // Nobody found: the region says so.
        $this->assertSame([], app(ProviderSearch::class)->search('Nobody', 'Here'));
    }

    public function test_the_report_region_is_found_however_the_page_writes_it(): void
    {
        $id = 'UkVHSU9OIFRZUEV-fjUwOTU4NTIxNTc1MjcwODM4';
        $sum = 'rvF3QuCVtN1eqipoHXVYcwg9d7n8kp1ooIb_H24nxaVjB5RuBZwkSd8l3oZ0';
        $item = rtrim(strtr(base64_encode('ITEM TYPE~~123'), '+/', '-_'), '=');
        foreach ([
            '"ajaxIdentifier":"'.$id.'\/'.$sum.'"',
            '"ajaxIdentifier":"'.$id.'/'.$sum.'"',
            'data-options="{&quot;ajaxIdentifier&quot;:&quot;'.$id.'/'.$sum.'&quot;}"',
            "ajaxIdentifier: '{$id}/{$sum}'",
        ] as $written) {
            $html = '<script>x("'.$item.'\/abc");</script><div id="R50958521575270838_ir" '.$written.'></div>';
            $this->assertSame(['ajax' => "{$id}/{$sum}", 'id' => '50958521575270838'], ProviderSearch::reportRegion($html), $written);
        }
        $this->assertNull(ProviderSearch::reportRegion('<p>No data found.</p>'));
    }

    public function test_what_the_site_sent_can_be_downloaded(): void
    {
        Storage::fake('local');
        $this->app->forgetInstance(ProviderSearch::class);
        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin)->get('/admin/ems-affiliations/last/page')->assertNotFound();
        Http::fake(['vdhems.vdh.virginia.gov/*' => Http::response('<html><body><p>Down for maintenance</p></body></html>')]);
        $this->actingAs($admin)->post('/admin/ems-affiliations/test', ['cert' => 'e1'])->assertSessionHas('error');
        $this->actingAs($admin)->get('/admin/ems-affiliations/last/page')->assertOk()->assertSee('Down for maintenance');
        $this->assertTrue(AuditLog::where('action', 'admin.ems.last')->exists());
        $this->actingAs($admin)->get('/admin/ems-affiliations/last/other')->assertNotFound();
    }

    public function test_agency_names_compare_without_punctuation_or_company_endings(): void
    {
        $this->assertSame('FASTTRACKEMS', Agency::key('FAST TRACK EMS, LLC'));
        $this->assertSame('FASTTRACKEMS', Agency::key('Fast Track EMS LLC'));
        $this->assertSame('SMITHANDSONSRESCUE', Agency::key('Smith & Sons Rescue, Inc.'));
        $this->assertSame('CO', Agency::key('Co'));
        $this->assertSame('Smith', Affiliations::lastName('John Smith Jr.'));
        $this->assertTrue(Affiliations::sameCert('0012345', '12345'));
    }
}
