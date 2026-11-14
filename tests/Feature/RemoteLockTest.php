<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PermissionProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\RemoteLock;
use App\Services\RemoteLockSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemoteLockTest extends TestCase
{
    use RefreshDatabase;

    /** RemoteLock as Pulse sees it: two access users, two door groups, and whatever gets created. */
    private array $people = [];

    /** While true, RemoteLock answers every call with an outage. */
    private bool $down = false;

    private function fakeRemoteLock(): void
    {
        $this->people = [
            'rl-1' => ['name' => 'Jim Smith', 'email' => 'jsmith@fasttrackems.com', 'status' => 'current'],
            'rl-2' => ['name' => 'Ana Lopez', 'email' => '', 'status' => 'current'],
            'rl-3' => ['name' => 'Vendor Cleaning', 'email' => '', 'status' => 'current'],
        ];
        $row = fn ($id) => ['type' => 'access_user', 'id' => $id, 'attributes' => $this->people[$id]];

        Http::fake(function (Request $request) use ($row) {
            if ($this->down) {
                return Http::response(['message' => 'down'], 503);
            }
            $url = $request->url();
            $path = parse_url($url, PHP_URL_PATH);
            if (str_starts_with($url, 'https://connect.remotelock.com/oauth/token')) {
                return $request['client_secret'] === 'good-secret'
                    ? Http::response(['access_token' => 'tok', 'expires_in' => 7200])
                    : Http::response(['error' => 'invalid_client'], 401);
            }
            if ($path === '/groups') {
                return Http::response(['data' => [
                    ['type' => 'door_group', 'id' => 'dg-main', 'attributes' => ['name' => 'Main office']],
                    ['type' => 'door_group', 'id' => 'dg-supply', 'attributes' => ['name' => 'Supply room']],
                    ['type' => 'location', 'id' => 'loc-1', 'attributes' => ['name' => 'Not a door group']],
                ], 'meta' => ['total_pages' => 1]]);
            }
            if ($path === '/access_persons' && $request->method() === 'GET') {
                return Http::response(['data' => array_map($row, array_keys($this->people)), 'meta' => ['total_pages' => 1]]);
            }
            if ($path === '/access_persons' && $request->method() === 'POST') {
                $id = 'rl-new-'.count($this->people);
                $this->people[$id] = ['name' => $request['attributes']['name'], 'email' => $request['attributes']['email'] ?? '', 'status' => 'current'];

                return Http::response(['data' => $row($id)], 201);
            }
            if (preg_match('#^/access_persons/([^/]+)/deactivate$#', $path, $m)) {
                $this->people[$m[1]]['status'] = 'deactivated';

                return Http::response(['data' => $row($m[1])]);
            }
            if (preg_match('#^/access_persons/([^/]+)/accesses$#', $path, $m)) {
                return $request->method() === 'GET'
                    ? Http::response(['data' => [], 'meta' => ['total_pages' => 1]])
                    : Http::response(['data' => ['type' => 'access_user_access', 'id' => 'acc', 'attributes' => $request['attributes']]], 201);
            }
            if (preg_match('#^/access_persons/([^/]+)$#', $path, $m)) {
                if (! isset($this->people[$m[1]])) {
                    return Http::response(['message' => 'Not found'], 404);
                }
                if ($request->method() === 'PUT') {
                    $this->people[$m[1]] = array_merge($this->people[$m[1]], $request['attributes']);
                }

                return Http::response(['data' => $row($m[1])]);
            }

            return Http::response(['message' => 'unexpected '.$request->method().' '.$url], 500);
        });
    }

    private function connect(): void
    {
        app(RemoteLock::class)->saveCredentials('client-1234-abcd', 'good-secret');
    }

    public function test_only_admin_starts_with_the_permission(): void
    {
        $this->assertTrue(PermissionProfile::where('name', 'Admin')->first()->allows('remotelock'));
        $this->assertFalse(PermissionProfile::where('name', 'Supervisor')->first()->allows('remotelock'));
        $this->actingAs(User::factory()->role('supervisor')->create())->get('/admin/remotelock')->assertForbidden();
        $this->actingAs(User::factory()->role('admin')->create())->get('/admin/remotelock')->assertOk()->assertSee('Not connected');
    }

    public function test_connecting_checks_the_credentials_and_keeps_the_secret_secret(): void
    {
        $this->fakeRemoteLock();
        $admin = User::factory()->role('admin')->create();

        $this->actingAs($admin)->put('/admin/remotelock', ['client_id' => 'client-1234-abcd', 'client_secret' => 'bad'])
            ->assertSessionHasErrors(['client_id' => 'RemoteLock turned down the client ID and secret. Copy them again from the RemoteLock developer portal.']);
        $this->assertFalse(app(RemoteLock::class)->connected());

        $this->actingAs($admin)->put('/admin/remotelock', ['client_id' => 'client-1234-abcd', 'client_secret' => 'good-secret'])
            ->assertSessionHas('status');
        $this->assertTrue(app(RemoteLock::class)->connected());
        $this->assertStringNotContainsString('good-secret', Setting::find('remotelock_credentials')->value);
        $log = AuditLog::latest('id')->first();
        $this->assertSame('admin.remotelock.connect', $log->action);
        $this->assertStringContainsString('RemoteLock client ID and secret from none to set', $log->description);
        $this->assertStringNotContainsString('good-secret', $log->description);

        $this->actingAs($admin)->get('/admin/remotelock')->assertOk()
            ->assertSee('3 access user(s) and 2 door group(s)')->assertSee('Main office')->assertDontSee('Not a door group');
    }

    public function test_matching_suggests_by_email_then_name_and_links(): void
    {
        $this->fakeRemoteLock();
        $this->connect();
        $admin = User::factory()->role('admin')->create(['name' => 'Pat Admin']);
        $james = User::factory()->create(['name' => 'James Smith', 'email' => 'jsmith@fasttrackems.com']);
        $ana = User::factory()->create(['name' => 'Ana  Lopez', 'email' => 'ana@fasttrackems.com']);

        $suggest = RemoteLockSync::suggest(collect([$james, $ana, $admin]), app(RemoteLock::class)->accessUsers());
        $this->assertSame(['id' => 'rl-1', 'why' => 'same email'], $suggest[$james->id]);
        $this->assertSame(['id' => 'rl-2', 'why' => 'same name'], $suggest[$ana->id]);
        $this->assertArrayNotHasKey($admin->id, $suggest);

        $this->actingAs($admin)->get('/admin/remotelock')->assertOk()->assertSee('Suggested: same email')->assertSee('Vendor Cleaning');

        $this->actingAs($admin)->post('/admin/remotelock/link', [
            'pick' => [$james->id => 1, $ana->id => 1],
            'match' => [$james->id => 'rl-1', $ana->id => 'rl-2'],
        ])->assertSessionHas('status', 'Linked 2 Team Member(s) to RemoteLock.');
        $this->assertSame('rl-1', $james->fresh()->remotelock_id);
        $this->assertSame('rl-2', $ana->fresh()->remotelock_id);
        $log = AuditLog::latest('id')->first();
        $this->assertSame('admin.remotelock.link', $log->action);
        $this->assertStringContainsString('James Smith from not linked to Jim Smith (jsmith@fasttrackems.com)', $log->description);

        // Linking never changes anything in RemoteLock.
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && ! str_contains($r->url(), 'oauth'));

        $this->actingAs($admin)->delete("/admin/remotelock/link/{$ana->id}")->assertSessionHas('status');
        $this->assertNull($ana->fresh()->remotelock_id);
        $this->assertStringContainsString('RemoteLock user from Ana Lopez to not linked', AuditLog::latest('id')->value('description'));
    }

    public function test_turning_someone_off_deactivates_them_and_edits_sync(): void
    {
        $this->fakeRemoteLock();
        $this->connect();
        $admin = User::factory()->role('admin')->create();
        $james = User::factory()->role('employee')->create(['name' => 'Jim Smith', 'email' => 'jsmith@fasttrackems.com']);
        $james->forceFill(['remotelock_id' => 'rl-1', 'remotelock_name' => 'Jim Smith'])->saveQuietly();

        $form = fn (array $over) => array_merge(['name' => 'James Smith', 'email' => 'jsmith@fasttrackems.com', 'permission_profile_id' => $james->permission_profile_id, 'active' => '1'], $over);

        // A name change goes to RemoteLock, and the log says from what to what.
        $this->actingAs($admin)->put("/admin/users/{$james->id}", $form([]))
            ->assertSessionHas('status', 'Saved James Smith. Updated James Smith in RemoteLock: name from Jim Smith to James Smith.');
        $this->assertStringContainsString('name from Jim Smith to James Smith', AuditLog::latest('id')->value('description'));
        $this->assertSame('James Smith', $this->people['rl-1']['name']);

        $this->actingAs($admin)->put("/admin/users/{$james->id}", $form(['active' => '0']))
            ->assertSessionHas('status', 'Saved James Smith. Deactivated James Smith in RemoteLock.');
        $this->assertSame('deactivated', $this->people['rl-1']['status']);
        $this->assertFalse($james->fresh()->remotelock_pending);
        $log = AuditLog::latest('id')->first();
        $this->assertSame('admin.users.update', $log->action);
        $this->assertStringContainsString('Deactivated James Smith in RemoteLock', $log->description);
        $this->assertStringContainsString('active from yes to no', $log->description);

        // Back on: RemoteLock can't reactivate, so a new access user is made and linked.
        $this->actingAs($admin)->put("/admin/users/{$james->id}", $form(['active' => '1']))->assertSessionHas('status');
        $this->assertNotSame('rl-1', $james->fresh()->remotelock_id);
        $this->assertSame('current', $this->people[$james->fresh()->remotelock_id]['status']);
    }

    public function test_new_team_members_get_their_profiles_door_groups(): void
    {
        $this->fakeRemoteLock();
        $this->connect();
        $admin = User::factory()->role('admin')->create();
        $member = PermissionProfile::where('name', 'Team Member')->first();
        $emt = PermissionProfile::create(['name' => 'EMT', 'parent_id' => $member->id, 'permissions' => []]);

        $this->actingAs($admin)->put('/admin/remotelock/doors', ['doors' => [$member->id => ['dg-main'], $emt->id => ['dg-supply', 'dg-main', 'made-up']]])
            ->assertSessionHas('status');
        $this->assertSame(['dg-main' => 'Main office'], $member->fresh()->remotelock_door_groups);
        // The inherited Main office isn't stored twice; the made-up id is dropped.
        $this->assertSame(['dg-supply' => 'Supply room'], $emt->fresh()->remotelock_door_groups);
        $this->assertStringContainsString('RemoteLock door groups from none to Supply room', AuditLog::latest('id')->value('description'));
        $this->assertSame(['dg-main' => 'Main office', 'dg-supply' => 'Supply room'], RemoteLockSync::doorGroupsFor($emt->fresh()->load('parent')));

        $this->actingAs($admin)->post('/admin/users', ['name' => 'Nina New', 'email' => 'nina@fasttrackems.com', 'permission_profile_id' => $emt->id])
            ->assertSessionHas('status', 'Added Nina New. Added Nina New to RemoteLock with a new PIN and door groups Main office, Supply room.');
        $nina = User::where('email', 'nina@fasttrackems.com')->first();
        $this->assertNotNull($nina->remotelock_id);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/access_persons') && $r['attributes']['generate_pin'] === true);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/accesses') && $r['attributes'] === ['accessible_type' => 'door_group', 'accessible_id' => 'dg-supply']);
        $this->assertStringContainsString('Added Nina New to RemoteLock', AuditLog::latest('id')->value('description'));
    }

    public function test_new_team_member_with_an_email_already_in_remotelock_is_linked_not_duplicated(): void
    {
        $this->fakeRemoteLock();
        $this->connect();
        $admin = User::factory()->role('admin')->create();

        $this->actingAs($admin)->post('/admin/users', ['name' => 'James Smith', 'email' => 'JSmith@fasttrackems.com', 'permission_profile_id' => PermissionProfile::default()->id])
            ->assertSessionHas('status', 'Added James Smith. Linked James Smith to the RemoteLock user Jim Smith with the same email.');
        $this->assertSame('rl-1', User::where('email', 'jsmith@fasttrackems.com')->value('remotelock_id'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/access_persons'));
    }

    public function test_auto_add_off_and_not_connected_leave_remotelock_alone(): void
    {
        $this->fakeRemoteLock();
        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin)->post('/admin/users', ['name' => 'A One', 'email' => 'a1@fasttrackems.com', 'permission_profile_id' => PermissionProfile::default()->id])
            ->assertSessionHas('status', 'Added A One.');

        $this->connect();
        $this->actingAs($admin)->put('/admin/remotelock/options', [])->assertSessionHas('status');
        $this->assertStringContainsString('Add new Team Members to RemoteLock from on to off', AuditLog::latest('id')->value('description'));
        $this->actingAs($admin)->post('/admin/users', ['name' => 'B Two', 'email' => 'b2@fasttrackems.com', 'permission_profile_id' => PermissionProfile::default()->id])
            ->assertSessionHas('status', 'Added B Two.');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/access_persons'));
    }

    public function test_failures_wait_for_the_scheduler_and_are_logged(): void
    {
        $this->connect();
        $this->fakeRemoteLock();
        $this->down = true;
        $admin = User::factory()->role('admin')->create();

        $this->actingAs($admin)->post('/admin/users', ['name' => 'Cal Retry', 'email' => 'cal@fasttrackems.com', 'permission_profile_id' => PermissionProfile::default()->id]);
        $cal = User::where('email', 'cal@fasttrackems.com')->first();
        $this->assertTrue($cal->remotelock_pending);
        $this->assertNotNull($cal->remotelock_error);

        $this->down = false;
        $this->artisan('remotelock:sync')->assertSuccessful();
        $cal->refresh();
        $this->assertFalse($cal->remotelock_pending);
        $this->assertNull($cal->remotelock_error);
        $this->assertNotNull($cal->remotelock_id);
        $this->assertSame('system.remotelock-sync', AuditLog::latest('id')->value('action'));
    }

    public function test_bulk_deactivate_for_people_already_off_in_pulse(): void
    {
        $this->fakeRemoteLock();
        $this->connect();
        $admin = User::factory()->role('admin')->create();
        $gone = User::factory()->create(['name' => 'Ana Lopez', 'active' => false]);
        $this->actingAs($admin)->post('/admin/remotelock/link', ['pick' => [$gone->id => 1], 'match' => [$gone->id => 'rl-2']]);

        $this->actingAs($admin)->get('/admin/remotelock')->assertSee('Off in Pulse, still active in RemoteLock (1)');
        $this->actingAs($admin)->post('/admin/remotelock/deactivate')->assertSessionHas('status', 'Deactivated 1 in RemoteLock.');
        $this->assertSame('deactivated', $this->people['rl-2']['status']);
        $this->assertStringContainsString('Ana Lopez (Ana Lopez) from current to deactivated', AuditLog::latest('id')->value('description'));
    }

    public function test_every_remotelock_route_has_an_audit_label(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();
            if ($name && str_starts_with($name, 'admin.remotelock.')) {
                $this->assertArrayHasKey($name, AuditLog::ACTIONS, $name);
            }
        }
    }
}
