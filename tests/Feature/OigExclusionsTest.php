<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OigCheck;
use App\Models\User;
use App\Services\OigExclusions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OigExclusionsTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = "LASTNAME,FIRSTNAME,MIDNAME,BUSNAME,GENERAL,SPECIALTY,UPIN,NPI,DOB,ADDRESS,CITY,STATE,ZIP,EXCLTYPE,EXCLDATE,REINDATE,WAIVERDATE,WVRSTATE\n";

    private function member(string $name, ?string $dob = null, bool $active = true): User
    {
        $user = User::factory()->create(['name' => $name, 'active' => $active]);
        if ($dob) {
            $user->setDob($dob);
            $user->save();
        }

        return $user;
    }

    private function fakeList(string $rows): void
    {
        Http::fake(['oig.hhs.gov/*' => Http::response(self::HEADER.$rows, 200, ['Last-Modified' => 'Tue, 10 Nov 2026 12:00:00 GMT'])]);
    }

    public function test_check_now_matches_names_and_dates_of_birth(): void
    {
        $admin = User::factory()->role('admin')->create(['name' => 'Ada Admin']);
        $match = $this->member('John Smith', '1980-05-01');
        $differentDob = $this->member('Mary Jones', '1990-01-01');
        $noDob = $this->member('Luis De La Cruz Jr');
        $clear = $this->member('Pat Crew', '1985-04-12');
        $inactive = $this->member('Old Smith', '1970-01-01', active: false);
        $this->fakeList(
            "SMITH,JOHN,A,,NURSING,NURSE/NURSES AIDE,,0,19800501,1 MAIN ST,RICHMOND,VA,23220,1128a1,20200115,00000000,00000000,\n"
            ."JONES,MARY,,,,,,0,19750303,,NORFOLK,VA,,1128b4,20190101,00000000,00000000,\n"
            ."DE LA CRUZ,LUIS,,,,,,0,00000000,,MIAMI,FL,,1128a1,20180101,00000000,00000000,\n"
            ."SMITH,OLD,,,,,,0,19700101,,,,,1128a1,20180101,00000000,00000000,\n"
            ."ACME AMBULANCE,,,ACME,,,,0,00000000,,,,,1128b7,20170101,00000000,00000000,\n"
        );

        $this->actingAs($admin)->post('/admin/oig')->assertRedirect()
            ->assertSessionHas('status', 'Checked 5 Team Members: 1 exclusion(s) found, 1 to check by hand.');

        $check = OigCheck::sole();
        $this->assertSame(5, $check->list_rows);
        $this->assertSame('2026-11-10', $check->list_updated_at->toDateString());
        $status = $check->results->pluck('status', 'user_id');
        $this->assertSame('excluded', $status[$match->id]);
        $this->assertSame('clear', $status[$differentDob->id]);
        $this->assertSame('review', $status[$noDob->id]);
        $this->assertSame('clear', $status[$clear->id]);
        $this->assertArrayNotHasKey($inactive->id, $status->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.oig.store', 'user_id' => $admin->id]);

        $this->actingAs($admin)->get('/admin/oig')->assertSee('1 exclusion found')->assertSee('1 to check by hand');
        $this->actingAs($admin)->get("/admin/oig/{$check->id}")
            ->assertSee('John Smith')->assertSee('05/01/XX')->assertDontSee('1980-05-01')->assertDontSee('05/01/1980')
            ->assertSee('Excluded')->assertSee('Not excluded')->assertSee('Needs manual check');
    }

    public function test_a_manual_check_is_recorded_and_changes_the_count(): void
    {
        $admin = User::factory()->role('admin')->create();
        $this->member('John Smith', '1980-05-01');
        $this->fakeList("SMITH,JOHN,,,,,,0,19800501,,,,,1128a1,20200115,00000000,00000000,\n");
        app(OigExclusions::class)->run($admin);
        $check = OigCheck::sole();
        $result = $check->results()->where('status', 'excluded')->sole();
        $this->assertSame(1, $check->excluded_count);

        $this->actingAs($admin)->post("/admin/oig/results/{$result->id}/review", ['outcome' => 'not_excluded', 'note' => 'SSN 123-45-6789 did not match'])
            ->assertSessionHasErrors(['note' => 'Leave the SSN out of the note.']);

        $pdf = UploadedFile::fake()->create('verify.pdf', 20, 'application/pdf');
        $this->actingAs($admin)->post("/admin/oig/results/{$result->id}/review", ['outcome' => 'not_excluded', 'note' => 'SSN did not match on exclusions.oig.hhs.gov', 'file' => $pdf])
            ->assertSessionHas('status');
        $result->refresh();
        $this->assertSame('Not excluded', $result->label());
        $this->assertSame(0, $check->fresh()->excluded_count);
        $this->assertSame(0, $check->fresh()->review_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.oig.review']);
        $this->actingAs($admin)->get("/admin/oig/results/{$result->id}/file")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.oig.file']);
        $this->actingAs($admin)->get('/admin/oig')->assertSee('0 exclusions found');
    }

    public function test_a_failed_download_is_kept_and_shown(): void
    {
        $admin = User::factory()->role('admin')->create();
        Http::fake(['oig.hhs.gov/*' => Http::response('nope', 503)]);

        $this->actingAs($admin)->post('/admin/oig')->assertSessionHas('error', "Couldn't download the OIG list (error 503).");
        $this->assertSame('failed', OigCheck::sole()->status);
        $this->actingAs($admin)->get('/admin/oig')->assertSee("Didn't run", false);
    }

    public function test_monthly_command_and_permission(): void
    {
        $this->member('Pat Crew', '1985-04-12');
        $this->fakeList("SMITH,JOHN,,,,,,0,19800501,,,,,1128a1,20200115,00000000,00000000,\n");
        $this->artisan('oig:check')->assertSuccessful();
        $this->assertNull(OigCheck::sole()->run_by);
        $this->assertTrue(AuditLog::where('action', 'system.oig-check')->exists());

        $supervisor = User::factory()->role('supervisor')->create();
        $this->actingAs($supervisor)->get('/admin/oig')->assertForbidden();
        $this->actingAs($supervisor)->post('/admin/oig')->assertForbidden();
    }

    public function test_name_keys_cover_compound_last_names_and_suffixes(): void
    {
        $this->assertSame(['LUIS', ['DELACRUZ', 'LACRUZ', 'CRUZ']], OigExclusions::nameKeys('Luis De La Cruz Jr.'));
        $this->assertSame(['MARY', ['ANNSMITHJONES', 'SMITHJONES']], OigExclusions::nameKeys('Mary Ann Smith-Jones'));
    }
}
