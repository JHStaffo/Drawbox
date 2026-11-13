<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\ProtocolImport\ReaderException;
use App\Training\Generate\ClaudeCourseWriter;
use App\Training\Generate\CourseGenerator;
use App\Training\Generate\CourseWriter;
use App\Training\Models\Course;
use App\Training\Models\Generation;
use App\Training\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrainingGenerateTest extends TestCase
{
    use RefreshDatabase;

    private FakeCourseWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new FakeCourseWriter;
        $this->app->instance(CourseWriter::class, $this->writer);
    }

    private function answer(): array
    {
        return [
            'title' => 'Safe Lifting',
            'description' => 'How to lift without getting hurt.',
            'category' => 'Safety',
            'lessons' => [
                ['title' => 'Why it matters', 'html' => '<h2>Backs</h2><p>Lift with your <strong>legs</strong>.</p><script>bad()</script>'],
                ['title' => 'Stair chair', 'html' => '<ol><li>Lock it</li><li>Tilt back</li></ol><blockquote><strong>Safety:</strong> two people.</blockquote>'],
                ['title' => 'Empty', 'html' => '<script>only()</script>'],
            ],
            'quiz_title' => 'Lifting quiz',
            'questions' => [
                ['type' => 'single', 'prompt' => 'Lift with your?', 'choices' => [['text' => 'Back', 'correct' => false], ['text' => 'Legs', 'correct' => true]], 'explanation' => 'Lesson 1.'],
                ['type' => 'multiple', 'prompt' => 'Before tilting a stair chair?', 'choices' => [['text' => 'Lock it', 'correct' => true], ['text' => 'Strap the patient', 'correct' => true], ['text' => 'Run', 'correct' => false]], 'explanation' => 'Lesson 2.'],
                ['type' => 'true_false', 'prompt' => 'One person can carry a stair chair down stairs.', 'choices' => [['text' => 'True', 'correct' => false], ['text' => 'False', 'correct' => true]], 'explanation' => 'Lesson 2.'],
                ['type' => 'single', 'prompt' => 'Broken: two right answers', 'choices' => [['text' => 'A', 'correct' => true], ['text' => 'B', 'correct' => true]], 'explanation' => ''],
                ['type' => 'single', 'prompt' => 'Broken: no right answer', 'choices' => [['text' => 'A', 'correct' => false], ['text' => 'B', 'correct' => false]], 'explanation' => ''],
            ],
            'notes' => 'Confirm the lift assist phone number.',
        ];
    }

    public function test_a_course_is_written_as_a_draft_with_pages_and_a_quiz(): void
    {
        Storage::fake('local');
        $admin = User::factory()->role('admin')->create();

        $this->actingAs($admin)->get('/training/generate')->assertOk()->assertSee('Write a course with AI');
        $this->actingAs($admin)->post('/training/generate', [
            'topic' => 'Safe lifting', 'audience' => 'New EMTs', 'goals' => 'Lift safely', 'lessons' => 3, 'questions' => 5, 'passing_score' => 70,
            'add_source_lesson' => 1, 'source' => UploadedFile::fake()->create('Lifting SOP.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $g = Generation::first();
        $this->assertSame('queued', $g->status);
        $this->assertSame(70, $g->brief['passing_score']);
        Storage::disk('local')->assertExists($g->source_path);
        $this->assertStringContainsString('Asked Claude to write a course: "Safe lifting" from Lifting SOP.pdf', AuditLog::where('action', 'training.generate.store')->first()->description);

        $this->writer->answers = [$this->answer()];
        $this->artisan('training:generate')->assertSuccessful();

        $g->refresh();
        $this->assertSame('done', $g->status);
        $this->assertSame('Safe lifting', $this->writer->briefs[0]['topic']);
        $this->assertSame('application/pdf', $this->writer->sources[0]['mime']);
        $course = Course::with('lessons.questions')->find($g->course_id);
        $this->assertSame('draft', $course->status);
        $this->assertSame('Safe Lifting', $course->title);
        $this->assertSame(['pdf', 'text', 'text', 'quiz'], $course->lessons->pluck('type')->all());
        $this->assertSame('Lifting SOP', $course->lessons[0]->title);
        $this->assertSame('<h2>Backs</h2><p>Lift with your <strong>legs</strong>.</p>', $course->lessons[1]->body);
        $quiz = $course->lessons[3];
        $this->assertSame(70, $quiz->passing_score);
        $this->assertSame(3, $quiz->questions->count());
        $this->assertSame([['text' => 'True', 'correct' => false], ['text' => 'False', 'correct' => true]], $quiz->questions[2]->choices);
        $this->assertSame('Confirm the lift assist phone number.', $g->notes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.training-generate']);

        $this->actingAs($admin)->get("/training/generate/{$g->id}")->assertOk()->assertSee('Review and edit the course')->assertSee('Confirm the lift assist phone number.');
    }

    public function test_busy_api_retries_and_other_errors_stop(): void
    {
        $admin = User::factory()->role('admin')->create();
        $g = Generation::create(['requested_by' => $admin->id, 'topic' => 'HIPAA', 'brief' => ['questions' => 0, 'passing_score' => 80]]);

        $this->writer->answers = [new ReaderException('Busy', true)];
        app(CourseGenerator::class)->runNext();
        $g->refresh();
        $this->assertSame('working', $g->status);
        $this->assertSame(1, $g->attempts);
        // It waits before the next try.
        $this->assertFalse(app(CourseGenerator::class)->runNext());

        $this->travel(3)->minutes();
        $this->writer->answers = [new ReaderException('Key turned down', false)];
        app(CourseGenerator::class)->runNext();
        $this->assertSame('failed', $g->fresh()->status);

        $this->actingAs($admin)->post("/training/generate/{$g->id}/retry")->assertSessionHas('status');
        $this->writer->answers = [['title' => 'HIPAA', 'description' => '', 'category' => '', 'lessons' => [['title' => 'Privacy', 'html' => '<p>Keep it private.</p>']], 'quiz_title' => '', 'questions' => [], 'notes' => null]];
        app(CourseGenerator::class)->runNext();
        $g->refresh();
        $this->assertSame('done', $g->status);
        $this->assertSame(['text'], $g->course->lessons->pluck('type')->all());
        $this->assertSame(0, Question::count());

        $this->actingAs($admin)->delete("/training/generate/{$g->id}")->assertRedirect('/training/generate');
        $this->assertNotNull(Course::find($g->course_id));
    }

    public function test_it_needs_a_key_and_the_build_permission(): void
    {
        $this->writer->ready = false;
        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin)->post('/training/generate', ['topic' => 'X', 'passing_score' => 80])->assertSessionHas('error');
        $this->assertSame(0, Generation::count());

        $member = User::factory()->role('employee')->create();
        $this->actingAs($member)->get('/training/generate')->assertForbidden();
    }

    public function test_the_prompt_and_schema(): void
    {
        $ask = ClaudeCourseWriter::ask(['topic' => 'Driving', 'audience' => 'Drivers', 'lessons' => 4, 'questions' => 8, 'passing_score' => 80], true);
        $this->assertStringContainsString('Topic: Driving', $ask);
        $this->assertStringContainsString('Who takes it: Drivers', $ask);
        $this->assertStringContainsString('8 questions', $ask);
        $this->assertStringContainsString('attached document', $ask);
        $this->assertStringContainsString('Return an empty questions list', ClaudeCourseWriter::ask(['topic' => 'X', 'questions' => 0], false));

        // Structured outputs need every object closed and every property required.
        $check = function (array $node) use (&$check) {
            if (($node['type'] ?? null) === 'object') {
                $this->assertFalse($node['additionalProperties']);
                $this->assertSame(array_keys($node['properties']), $node['required']);
            }
            foreach (['properties', 'items', 'anyOf'] as $k) {
                foreach ((array) ($node[$k] ?? []) as $child) {
                    if (is_array($child)) {
                        $check($child);
                    }
                }
            }
        };
        $check(ClaudeCourseWriter::schema());
        $this->assertFalse((new ClaudeCourseWriter(null))->ready());
    }
}

class FakeCourseWriter implements CourseWriter
{
    public bool $ready = true;

    /** @var list<array|\Throwable> */
    public array $answers = [];

    public array $briefs = [];

    public array $sources = [];

    public function ready(): bool
    {
        return $this->ready;
    }

    public function write(array $brief, ?array $source): array
    {
        $this->briefs[] = $brief;
        $this->sources[] = $source;
        $answer = array_shift($this->answers);
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return ['course' => $answer, 'notes' => $answer['notes'] ?? null, 'input_tokens' => 1000, 'output_tokens' => 4000];
    }
}
