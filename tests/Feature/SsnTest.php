<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\Ssn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SsnTest extends TestCase
{
    use RefreshDatabase;

    private function noSsnInAuditLog(): void
    {
        foreach (AuditLog::pluck('description') as $description) {
            $this->assertDoesNotMatchRegularExpression('/\d{3}-?\d{2}-?\d{4}/', $description);
        }
    }

    public function test_ssn_is_encrypted_with_its_own_key_and_never_in_json(): void
    {
        $pat = User::factory()->create();
        $pat->setSsn(Ssn::normalize('123-45-6789'));
        $pat->save();

        $row = DB::table('users')->where('id', $pat->id)->first();
        $this->assertStringNotContainsString('123456789', $row->ssn_encrypted);
        $this->assertSame('6789', $row->ssn_last4);
        $this->assertSame(64, strlen($row->ssn_hash));
        $this->assertSame('123-45-6789', $pat->fresh()->ssn());
        $this->assertSame('•••-••-6789', $pat->fresh()->ssnMasked());
        $this->assertTrue($pat->fresh()->is(User::withSsn('123456789')));

        $json = json_encode($pat->fresh());
        $this->assertStringNotContainsString('ssn', $json);
        $this->assertStringNotContainsString('6789', $json);

        // APP_KEY can't read it.
        $this->assertThrows(fn () => \Illuminate\Support\Facades\Crypt::decryptString($row->ssn_encrypted), \Illuminate\Contracts\Encryption\DecryptException::class);
    }

    public function test_nothing_is_saved_without_the_ssn_key(): void
    {
        config(['app.ssn_key' => null]);
        $admin = User::factory()->role('admin')->create();
        $pat = User::factory()->create();

        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertDontSee('name="ssn"', false);
        $this->actingAs($admin)->put("/admin/users/{$pat->id}/ssn", ['ssn' => '123-45-6789'])
            ->assertSessionHasErrors(['ssn' => "SSNs can't be saved until the server's SSN key is set."]);
        $this->assertFalse($pat->fresh()->hasSsn());
    }

    public function test_only_real_looking_ssns_are_accepted(): void
    {
        $this->assertSame('123456789', Ssn::normalize(' 123 45 6789 '));
        foreach (['12345678', '000-12-3456', '666-12-3456', '912-34-5678', '123-00-4567', '123-45-0000', 'abc-de-fghi'] as $bad) {
            $this->assertNull(Ssn::normalize($bad), $bad);
        }
    }

    public function test_permissions_control_seeing_revealing_and_changing(): void
    {
        $admin = User::factory()->role('admin')->create();
        $supervisor = User::factory()->role('supervisor')->create();
        $supervisor->profile->update(['permissions' => array_merge($supervisor->profile->permissions ?? [], ['team_members'])]);
        $pat = User::factory()->create(['name' => 'Pat Crew']);
        $pat->setSsn('123456789');
        $pat->save();

        // Team Members access alone: no SSN column, no reveal, no change.
        $this->actingAs($supervisor)->get('/admin/users')->assertOk()->assertDontSee('6789')->assertDontSee('>SSN<', false);
        $this->actingAs($supervisor)->postJson("/admin/users/{$pat->id}/ssn")->assertForbidden();
        $this->actingAs($supervisor)->put("/admin/users/{$pat->id}/ssn", ['ssn' => '234-56-7890'])->assertForbidden();
        $this->assertSame('123-45-6789', $pat->fresh()->ssn());

        // Admin starts with both and sees the last 4 only until they press Show.
        $this->assertTrue($admin->hasPermission('ssn_view') && $admin->hasPermission('ssn_edit'));
        $this->actingAs($admin)->get('/admin/users')->assertSee('•••-••-6789')->assertDontSee('123-45-6789');
        $this->actingAs($admin)->postJson("/admin/users/{$pat->id}/ssn")->assertOk()->assertExactJson(['ssn' => '123-45-6789'])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.users.ssn.reveal', 'user_id' => $admin->id, 'description' => "Looked at Pat Crew's full SSN."]);

        // A supervisor can't hand out the SSN permissions they don't have.
        $this->assertFalse($supervisor->canGrant(['ssn_view']));

        $this->noSsnInAuditLog();
    }

    public function test_adding_changing_and_removing_an_ssn_is_audited_without_the_number(): void
    {
        $admin = User::factory()->role('admin')->create();
        $profile = PermissionProfile::default()->id;

        $this->actingAs($admin)->post('/admin/users', ['name' => 'Pat Crew', 'email' => 'pat@example.com', 'ssn' => '123-45-6789', 'permission_profile_id' => $profile])
            ->assertSessionHas('status', 'Added Pat Crew. SSN saved.');
        $pat = User::where('email', 'pat@example.com')->first();
        $this->assertSame('123-45-6789', $pat->ssn());

        // A bad number isn't saved, and isn't put back in the form.
        $this->actingAs($admin)->put("/admin/users/{$pat->id}/ssn", ['ssn' => '666-12-3456'])->assertSessionHasErrors('ssn');
        $this->assertNull(session()->getOldInput('ssn'));

        // Someone else's number is refused.
        $other = User::factory()->create();
        $this->actingAs($admin)->put("/admin/users/{$other->id}/ssn", ['ssn' => '123456789'])->assertSessionHasErrors(['ssn' => 'That SSN is already on file for another Team Member.']);
        $this->assertFalse($other->fresh()->hasSsn());

        $this->actingAs($admin)->put("/admin/users/{$pat->id}/ssn", ['ssn' => '234-56-7890'])->assertSessionHas('status', "Changed Pat Crew's SSN.");
        $this->assertSame('234-56-7890', $pat->fresh()->ssn());
        $this->actingAs($admin)->put("/admin/users/{$pat->id}/ssn", ['remove' => 1])->assertSessionHas('status', "Removed Pat Crew's SSN.");
        $this->assertFalse($pat->fresh()->hasSsn());
        $this->assertNull(DB::table('users')->where('id', $pat->id)->value('ssn_hash'));

        $this->assertSame(4, AuditLog::where('action', 'admin.users.ssn.update')->count()); // 2 refused, 1 change, 1 remove
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.users.ssn.update', 'description' => "Changed Pat Crew's SSN."]);
        $this->noSsnInAuditLog();
    }

    public function test_csv_import_fills_in_blank_ssns_only(): void
    {
        $admin = User::factory()->role('admin')->create();
        $old = User::factory()->create(['email' => 'old@example.com']);
        $kept = User::factory()->create(['email' => 'kept@example.com']);
        $kept->setSsn('111223333');
        $kept->save();
        $csv = "First Name,Last Name,Email,SSN\n"
            ."Amy,Zimmer,amy@example.com,234-56-7890\n"
            ."Bo,Bad,bo@example.com,000-11-2222\n"
            ."Cy,Copy,cy@example.com,111-22-3333\n"
            ."Old,Timer,old@example.com,345678901\n"
            ."Kept,Number,kept@example.com,456-78-9012\n";
        $file = UploadedFile::fake()->createWithContent('team.csv', $csv);

        $this->actingAs($admin)->post('/admin/users/import', ['file' => $file])
            ->assertSessionHas('status', 'Added 3 team member(s). Skipped 2 already on the roster. Saved 2 SSN(s). Left out the SSN on line(s) 3, 4 because it isn\'t valid or is already on file for someone else.');

        $this->assertSame('234-56-7890', User::where('email', 'amy@example.com')->first()->ssn());
        $this->assertFalse(User::where('email', 'bo@example.com')->first()->hasSsn());
        $this->assertFalse(User::where('email', 'cy@example.com')->first()->hasSsn());
        $this->assertSame('345-67-8901', $old->fresh()->ssn());
        $this->assertSame('111-22-3333', $kept->fresh()->ssn());
        $this->noSsnInAuditLog();
    }

    public function test_csv_ssn_column_is_ignored_without_the_permission(): void
    {
        $manager = User::factory()->role('supervisor')->create();
        $manager->profile->update(['permissions' => array_merge($manager->profile->permissions ?? [], ['team_members'])]);
        $file = UploadedFile::fake()->createWithContent('team.csv', "First Name,Last Name,Email,SSN\nAmy,Zimmer,amy@example.com,234-56-7890\n");

        $this->actingAs($manager)->post('/admin/users/import', ['file' => $file])
            ->assertSessionHas('status', 'Added 1 team member(s). Left out the SSN column because you can\'t add SSNs.');
        $this->assertFalse(User::where('email', 'amy@example.com')->first()->hasSsn());
    }

    public function test_ssn_never_reaches_the_api(): void
    {
        $pat = User::factory()->create();
        $pat->setSsn('123456789');
        $pat->save();
        $token = $pat->createToken('test')->plainTextToken;

        $body = $this->withToken($token)->getJson('/api/v1/me')->assertOk()->getContent();
        $this->assertStringNotContainsString('ssn', $body);
        $this->assertStringNotContainsString('6789', $body);
    }
}
