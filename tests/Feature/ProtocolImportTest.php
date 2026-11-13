<?php

namespace Tests\Feature;

use App\Models\PermissionProfile;
use App\Models\Protocol;
use App\Models\ProtocolCategory;
use App\Models\ProtocolImport;
use App\Models\User;
use App\Services\ProtocolImport\ClaudeProtocolReader;
use App\Services\ProtocolImport\ProtocolImporter;
use App\Services\ProtocolImport\ProtocolReader;
use App\Services\ProtocolImport\ReaderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProtocolImportTest extends TestCase
{
    use RefreshDatabase;

    /** Answers from a list instead of calling the Claude API. */
    private FakeReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->reader = new FakeReader;
        $this->app->instance(ProtocolReader::class, $this->reader);
    }

    private function builder(): User
    {
        $p = PermissionProfile::where('name', 'Supervisor')->first();
        $p->update(['permissions' => [...$p->permissions, 'protocols', 'protocols_build']]);

        return User::factory()->role('supervisor')->create(['name' => 'Sam Builder']);
    }

    private function pdf(int $pages): UploadedFile
    {
        $body = "%PDF-1.4\n1 0 obj << /Type /Pages /Count {$pages} >> endobj\n";
        for ($i = 0; $i < $pages; $i++) {
            $body .= ($i + 2)." 0 obj << /Type /Page /Parent 1 0 R >> endobj\n";
        }

        return UploadedFile::fake()->createWithContent('book.pdf', $body."%%EOF\n");
    }

    private function protocol(string $title, array $extra = []): array
    {
        return $extra + [
            'number' => null, 'title' => $title, 'category' => 'Cardiac Arrest', 'ages' => ['adult'], 'revised' => 'Revised 2020',
            'boxes' => [
                ['id' => 'a', 'type' => 'decision', 'text' => 'Pulse?', 'badges' => [], 'exit_to' => null, 'x' => 300, 'y' => 40],
                ['id' => 'b', 'type' => 'exit', 'text' => "**EXIT** to ROSC\nProtocol", 'badges' => [], 'exit_to' => 'ROSC', 'x' => 100, 'y' => 200],
                ['id' => 'c', 'type' => 'step', 'text' => "**Epinephrine**\n1 mg IV/IO", 'badges' => ['P', 'peds'], 'exit_to' => null, 'x' => 500, 'y' => 200],
            ],
            'arrows' => [
                ['from' => 'a', 'to' => 'b', 'label' => 'YES', 'style' => 'one', 'from_side' => 'left', 'to_side' => 'top'],
                ['from' => 'a', 'to' => 'c', 'label' => 'NO', 'style' => 'one', 'from_side' => 'right', 'to_side' => 'top'],
                ['from' => 'a', 'to' => 'zzz', 'label' => null, 'style' => 'one', 'from_side' => 'bottom', 'to_side' => 'top'],
            ],
            'sections' => [
                ['heading' => 'Goals', 'color' => 'purple', 'badges' => [], 'text' => "- Early CPR\n- Early defibrillation"],
                ['heading' => 'Pediatrics', 'color' => 'pink', 'badges' => ['peds'], 'text' => 'Epinephrine 0.01 mg/kg'],
            ],
        ];
    }

    public function test_builders_upload_and_cron_reads_two_pages_at_a_time(): void
    {
        $user = $this->builder();
        $this->actingAs($user)->get('/protocols/imports')->assertOk()->assertSee('Upload and import');
        $this->actingAs(User::factory()->role('employee')->create())->get('/protocols/imports')->assertForbidden();

        $this->actingAs($user)->post('/protocols/imports', ['files' => [$this->pdf(3)]])->assertRedirect('/protocols/imports');
        $import = ProtocolImport::sole();
        $this->assertSame('pdf', $import->kind);
        $this->assertSame(3, $import->page_count);
        Storage::disk('local')->assertExists($import->files[0]['path']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'protocols.imports.store']);

        $this->reader->answers = [
            ['protocols' => [$this->protocol('Cardiac Arrest')], 'notes' => 'Box 4 was blurry.', 'input_tokens' => 20000, 'output_tokens' => 3000],
            ['protocols' => [$this->protocol('ROSC', ['category' => 'cardiac arrest', 'ages' => ['adult', 'pediatric']])], 'notes' => null, 'input_tokens' => 3000, 'output_tokens' => 2000],
        ];
        $this->artisan('protocols:import')->assertSuccessful();

        $this->assertSame([[1, 2], [3, 3]], $this->reader->pages);
        $import->refresh();
        $this->assertSame('done', $import->status);
        $this->assertCount(2, $import->protocol_ids);
        $this->assertSame(['Pages 1-2: Box 4 was blurry.'], $import->notes);
        $this->assertSame(0.19, $import->cost());

        $arrest = Protocol::find($import->protocol_ids[0])->openVersion();
        $rosc = Protocol::find($import->protocol_ids[1]);
        $this->assertSame('draft', $arrest->status);
        $this->assertSame($user->id, $arrest->created_by);
        $this->assertSame('Cardiac Arrest', $arrest->category_name);
        $this->assertSame(1, ProtocolCategory::where('name', 'Cardiac Arrest')->count(), 'category matched, not duplicated');
        $this->assertSame('manual', $arrest->chart['layout']);
        $this->assertSame(['n1', 'n2', 'n3'], array_column($arrest->boxes(), 'id'));
        $this->assertSame(['P', 'peds'], $arrest->boxes()[2]['badges']);
        $this->assertSame(150, min(array_column($arrest->boxes(), 'x')), 'shifted onto the canvas');
        $this->assertCount(2, $arrest->arrows(), 'the arrow to a missing box is dropped');
        $this->assertSame('YES', $arrest->arrows()[0]['label']);
        $this->assertSame($rosc->id, $arrest->boxes()[1]['link'], 'Exit to ROSC opens the imported ROSC protocol');
        $this->assertSame(['pediatric', 'adult'], $rosc->openVersion()->ages);
        $this->assertStringContainsString('Early defibrillation', $arrest->search_text);
        $this->assertFalse(Protocol::inBook()->exists(), 'imports never reach the book without approval');

        $this->assertDatabaseHas('audit_logs', ['action' => 'system.protocol-import', 'description' => 'Finished reading book.pdf: 2 draft protocol(s), 1 box link(s) set, about $0.19 of API use.']);
        $this->actingAs($user)->get('/protocols/imports')->assertSee('Cardiac Arrest')->assertSee('ROSC')->assertSee('Box 4 was blurry.');
    }

    public function test_photos_are_read_together_and_unknown_exits_are_noted(): void
    {
        $user = $this->builder();
        $this->actingAs($user)->post('/protocols/imports', ['files' => [
            UploadedFile::fake()->image('page1.jpg'), UploadedFile::fake()->image('page2.png'),
        ]])->assertRedirect();
        $import = ProtocolImport::sole();
        $this->assertSame('images', $import->kind);
        $this->assertSame('page1.jpg and 1 more', $import->name);

        $this->reader->answers = [['protocols' => [$this->protocol('Cardiac Arrest', ['category' => 'Brand New'])], 'notes' => null, 'input_tokens' => 1, 'output_tokens' => 1]];
        $this->artisan('protocols:import');

        $this->assertSame([null], $this->reader->pages);
        $this->assertSame(['image/jpeg', 'image/png'], array_column($this->reader->files[0], 'mime'));
        $import->refresh();
        $this->assertSame('done', $import->status);
        $this->assertStringContainsString('names the ROSC protocol, which isn\'t in the system yet', $import->notes[0]);
        $this->assertTrue(ProtocolCategory::where('name', 'Brand New')->exists());
    }

    public function test_busy_api_is_retried_and_a_hard_failure_stops(): void
    {
        $user = $this->builder();
        $this->actingAs($user)->post('/protocols/imports', ['files' => [$this->pdf(2)]]);
        $import = ProtocolImport::sole();

        $this->reader->answers = [new ReaderException('busy', true)];
        app(ProtocolImporter::class)->runNext();
        $import->refresh();
        $this->assertSame('working', $import->status);
        $this->assertSame(1, $import->attempts);
        $this->assertFalse(app(ProtocolImporter::class)->runNext(), 'waits before trying again');

        $this->travel(3)->minutes();
        $this->reader->answers = [new ReaderException('The key was turned down.')];
        app(ProtocolImporter::class)->runNext();
        $import->refresh();
        $this->assertSame('failed', $import->status);
        $this->assertSame('The key was turned down.', $import->error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.protocol-import', 'description' => 'Stopped reading book.pdf at pages 1-2: The key was turned down.']);

        $this->actingAs($user)->post("/protocols/imports/{$import->id}/retry")->assertRedirect();
        $this->assertSame('working', $import->fresh()->status);
        $this->reader->answers = [['protocols' => [], 'notes' => null, 'input_tokens' => 0, 'output_tokens' => 0]];
        app(ProtocolImporter::class)->runNext();
        $this->assertSame('done', $import->fresh()->status);

        $this->actingAs($user)->delete("/protocols/imports/{$import->id}")->assertRedirect();
        $this->assertNull($import->fresh());
        Storage::disk('local')->assertMissing('protocol-imports/'.$import->id.'/1.pdf');
        $this->assertDatabaseHas('audit_logs', ['action' => 'protocols.imports.destroy']);
    }

    public function test_upload_rules_and_missing_key(): void
    {
        $user = $this->builder();
        $this->actingAs($user)->post('/protocols/imports', ['files' => [$this->pdf(1), UploadedFile::fake()->image('a.jpg')]])
            ->assertSessionHasErrors('files');
        $this->actingAs($user)->post('/protocols/imports', ['files' => [UploadedFile::fake()->create('a.docx', 10, 'application/msword')]])
            ->assertSessionHasErrors('files.0');
        $this->assertSame(0, ProtocolImport::count());

        $this->reader->ready = false;
        $this->actingAs($user)->get('/protocols/imports')->assertSee('ANTHROPIC_API_KEY');
        $this->actingAs($user)->post('/protocols/imports', ['files' => [$this->pdf(1)]])->assertSessionHas('error');
        $this->assertSame(0, ProtocolImport::count());
    }

    public function test_the_answer_schema_follows_structured_output_rules(): void
    {
        $check = function (array $schema) use (&$check) {
            if (($schema['type'] ?? null) === 'object') {
                $this->assertFalse($schema['additionalProperties']);
                $this->assertSame(array_keys($schema['properties']), $schema['required']);
            }
            foreach ([...($schema['properties'] ?? []), ...(isset($schema['items']) ? [$schema['items']] : []), ...($schema['anyOf'] ?? [])] as $child) {
                $check($child);
            }
        };
        $check(ClaudeProtocolReader::schema());
        $this->assertFalse((new ClaudeProtocolReader(null))->ready());
    }
}

class FakeReader implements ProtocolReader
{
    public bool $ready = true;

    public array $answers = [];

    public array $pages = [];

    public array $files = [];

    public function ready(): bool
    {
        return $this->ready;
    }

    public function read(array $files, ?array $pages): array
    {
        $this->files[] = $files;
        $this->pages[] = $pages;
        foreach ($files as $f) {
            if (! is_file($f['path'])) {
                throw new \RuntimeException("missing {$f['path']}");
            }
        }
        $answer = array_shift($this->answers) ?? throw new \RuntimeException('No answer queued');
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }
}
