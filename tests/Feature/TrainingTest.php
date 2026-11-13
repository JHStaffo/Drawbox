<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Training\Models\Assignment;
use App\Training\Models\Attempt;
use App\Training\Models\Course;
use App\Training\Models\Lesson;
use App\Training\Models\LessonProgress;
use App\Training\Models\Question;
use App\Training\Models\Section;
use App\Training\Models\TrainingProfile;
use App\Training\RichText;
use App\Training\Training;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrainingTest extends TestCase
{
    use RefreshDatabase;

    private function grant(string $profile, array $permissions): void
    {
        $p = PermissionProfile::where('name', $profile)->first();
        $p->update(['permissions' => array_values(array_unique([...$p->permissions, ...$permissions]))]);
    }

    private function admin(): User
    {
        return User::factory()->role('admin')->create(['name' => 'Alex Admin']);
    }

    private function member(string $name = 'Terry Member'): User
    {
        $this->grant('Team Member', ['training']);

        return User::factory()->role('employee')->create(['name' => $name]);
    }

    /** A published course: a text page, then a quiz with two questions (pass 50%, 2 attempts). */
    private function course(): Course
    {
        $course = Course::create(['title' => 'Employee Handbook', 'status' => 'published', 'due_days' => 14]);
        $course->lessons()->create(['title' => 'Welcome', 'type' => 'text', 'position' => 1, 'body' => '<p>Hello</p>']);
        $quiz = $course->lessons()->create(['title' => 'Handbook quiz', 'type' => 'quiz', 'position' => 2, 'passing_score' => 50, 'max_attempts' => 2]);
        $quiz->questions()->create(['type' => 'single', 'prompt' => 'Who do you call?', 'position' => 1, 'choices' => [['text' => 'Dispatch', 'correct' => true], ['text' => 'Nobody', 'correct' => false]]]);
        $quiz->questions()->create(['type' => 'multiple', 'prompt' => 'Pick the PPE', 'position' => 2, 'choices' => [['text' => 'Gloves', 'correct' => true], ['text' => 'Mask', 'correct' => true], ['text' => 'Sandals', 'correct' => false]]]);

        return $course->fresh();
    }

    public function test_only_admin_starts_with_training_permissions(): void
    {
        $this->assertTrue(PermissionProfile::where('name', 'Admin')->first()->allows('training_build'));
        $this->assertFalse(PermissionProfile::where('name', 'Team Member')->first()->allows('training'));

        $member = User::factory()->role('employee')->create();
        $this->actingAs($member)->get('/training')->assertForbidden();
        $this->actingAs($member)->get('/')->assertDontSee('My Training');

        $this->actingAs($this->admin())->get('/')->assertSee('Training')->assertSee('Training profiles');
    }

    public function test_a_course_is_built_with_lessons_files_and_a_quiz(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        $this->actingAs($admin)->post('/training/courses', ['title' => 'Driving Policy'])->assertRedirect();
        $course = Course::first();
        $this->assertSame('draft', $course->status);

        // Can't publish without lessons.
        $this->actingAs($admin)->put("/training/courses/{$course->id}", ['title' => 'Driving Policy', 'status' => 'published'])->assertSessionHas('error');

        $this->actingAs($admin)->post("/training/courses/{$course->id}/lessons", ['title' => 'The policy', 'type' => 'pdf'])->assertRedirect();
        $pdf = Lesson::where('type', 'pdf')->first();
        $this->actingAs($admin)->put("/training/lessons/{$pdf->id}", ['title' => 'The policy'])->assertSessionHasErrors('upload');
        $this->actingAs($admin)->put("/training/lessons/{$pdf->id}", [
            'title' => 'The policy', 'min_minutes' => 2,
            'upload' => UploadedFile::fake()->create('policy.pdf', 200, 'application/pdf'),
            'body' => '<h2>Read this</h2><script>alert(1)</script><p onclick="x()">Then sign.</p>',
        ])->assertSessionHasNoErrors();
        $pdf->refresh();
        $this->assertSame('policy.pdf', $pdf->file_name);
        $this->assertSame(120, $pdf->min_seconds);
        $this->assertSame('<h2>Read this</h2><p>Then sign.</p>', $pdf->body);
        Storage::disk('local')->assertExists($pdf->file_path);
        $this->actingAs($admin)->get("/training/lessons/{$pdf->id}/file")->assertOk();

        // A PowerPoint lesson takes only slides.
        $this->actingAs($admin)->post("/training/courses/{$course->id}/lessons", ['title' => 'Slides', 'type' => 'slides']);
        $slides = Lesson::where('type', 'slides')->first();
        $this->actingAs($admin)->put("/training/lessons/{$slides->id}", ['title' => 'Slides', 'upload' => UploadedFile::fake()->create('notes.mp3', 10)])->assertSessionHasErrors('upload');
        $this->actingAs($admin)->put("/training/lessons/{$slides->id}", ['title' => 'Slides', 'upload' => UploadedFile::fake()->create('deck.pptx', 100)])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get("/training/lessons/{$slides->id}/preview")->assertOk()->assertSee('view.officeapps.live.com', false);

        // Quiz questions.
        $this->actingAs($admin)->post("/training/courses/{$course->id}/lessons", ['title' => 'Quiz', 'type' => 'quiz']);
        $quiz = Lesson::where('type', 'quiz')->first();
        $this->assertSame(80, $quiz->passing());
        $this->actingAs($admin)->put("/training/courses/{$course->id}", ['title' => 'Driving Policy', 'status' => 'published'])->assertSessionHas('error');
        $this->actingAs($admin)->post("/training/lessons/{$quiz->id}/questions", ['type' => 'single', 'prompt' => 'Speed limit?', 'choices' => ['55', '65'], 'correct' => [0, 1]])
            ->assertSessionHasErrors('correct');
        $this->actingAs($admin)->post("/training/lessons/{$quiz->id}/questions", ['type' => 'single', 'prompt' => 'Speed limit?', 'choices' => ['55', '', '65'], 'correct' => [2]])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/training/lessons/{$quiz->id}/questions", ['type' => 'true_false', 'prompt' => 'Lights on in rain?', 'true_false' => 'true'])->assertSessionHasNoErrors();
        $q = Question::first();
        $this->assertSame([['text' => '55', 'correct' => false], ['text' => '65', 'correct' => true]], $q->choices);

        $this->actingAs($admin)->put("/training/courses/{$course->id}", ['title' => 'Driving Policy', 'status' => 'published'])->assertSessionHasNoErrors();
        $this->assertSame('published', $course->fresh()->status);

        $this->assertDatabaseHas('audit_logs', ['action' => 'training.lessons.update']);
        $this->assertStringContainsString('Lights on in rain?', AuditLog::where('action', 'training.questions.store')->latest('id')->first()->description);
        $this->assertStringContainsString('status from draft to published', AuditLog::where('action', 'training.courses.update')->latest('id')->first()->description);
    }

    public function test_the_designer_keeps_formatting_and_drops_anything_risky(): void
    {
        $html = '<h1 class="ql-align-center evil">Title</h1><p><strong>Bold</strong> <a href="javascript:alert(1)">bad</a> <a href="https://fasttrackems.com">good</a></p>'
            .'<ol><li data-list="bullet">One</li></ol><img src="data:image/png;base64,AAAA"><img src="/training/images/abc.png" onerror="x()">'
            .'<iframe class="ql-video" src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"></iframe><iframe src="https://evil.example/"></iframe>'
            .'<p style="color: #ff0000; position: fixed">Red</p><style>body{}</style>';
        $clean = RichText::clean($html);

        $this->assertStringContainsString('<h1 class="ql-align-center">Title</h1>', $clean);
        $this->assertStringContainsString('<strong>Bold</strong>', $clean);
        $this->assertStringNotContainsString('javascript', $clean);
        $this->assertStringContainsString('href="https://fasttrackems.com" target="_blank"', $clean);
        $this->assertStringContainsString('<li data-list="bullet">One</li>', $clean);
        $this->assertStringNotContainsString('data:image', $clean);
        $this->assertStringContainsString('<img src="/training/images/abc.png">', $clean);
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $clean);
        $this->assertStringNotContainsString('evil.example', $clean);
        $this->assertStringContainsString('<p style="color: #ff0000">Red</p>', $clean);
        $this->assertStringNotContainsString('<style', $clean);
        $this->assertSame('', RichText::clean('<p><br></p>'));
    }

    public function test_designer_pictures_upload_and_load_without_signing_in(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $url = $this->actingAs($admin)->post('/training/images', ['image' => UploadedFile::fake()->image('map.png')])->assertOk()->json('url');
        $this->assertMatchesRegularExpression('~^/training/images/[A-Za-z0-9]{40}\.png$~', $url);
        auth()->logout();
        $this->get($url)->assertOk();
        $this->get('/training/images/../../.env')->assertNotFound();
    }

    public function test_a_team_member_takes_a_course_with_time_tracking_and_a_quiz(): void
    {
        $this->travelTo(now()->setTime(9, 0));
        $member = $this->member();
        $course = $this->course();
        [$text, $quiz] = $course->lessons->all();
        $training = app(Training::class);
        $a = $training->assign($member, $course, $this->admin());
        $this->assertSame(today()->addDays(14)->toDateString(), $a->due_on->toDateString());

        $this->actingAs($member)->get('/training')->assertOk()->assertSee('Employee Handbook');
        $this->actingAs($member)->get("/training/a/{$a->id}/lessons/{$text->id}")->assertOk()->assertSee('Hello')->assertSee('Mark as done');
        $this->assertNotNull($a->fresh()->started_at);

        // Time: a report adds at most what has passed since the last one.
        $this->actingAs($member)->postJson("/training/a/{$a->id}/lessons/{$text->id}/time", ['seconds' => 15])->assertJson(['added' => 15]);
        $this->actingAs($member)->postJson("/training/a/{$a->id}/lessons/{$text->id}/time", ['seconds' => 15])->assertJson(['added' => 2]);
        $this->travel(20)->seconds();
        $this->actingAs($member)->postJson("/training/a/{$a->id}/lessons/{$text->id}/time", ['seconds' => 500])->assertJson(['added' => 22]);
        $this->assertSame(39, $a->fresh()->seconds_spent);
        $this->assertSame(0, AuditLog::where('action', 'training.time')->count());

        // Someone else can't report time on it.
        $other = $this->member('Other Person');
        $this->actingAs($other)->postJson("/training/a/{$a->id}/lessons/{$text->id}/time", ['seconds' => 15])->assertForbidden();
        $this->actingAs($other)->get("/training/a/{$a->id}")->assertForbidden();

        $this->actingAs($member)->post("/training/a/{$a->id}/lessons/{$text->id}/done")->assertRedirect("/training/a/{$a->id}/lessons/{$quiz->id}");
        $this->assertSame(50, $a->fresh()->percent);
        $this->actingAs($member)->post("/training/a/{$a->id}/lessons/{$quiz->id}/done")->assertSessionHas('error');

        // Attempt 1: one right of two = 50%... but the "select all" answer is incomplete, so 1 of 2 wrong.
        [$q1, $q2] = $quiz->questions->all();
        $this->actingAs($member)->post("/training/a/{$a->id}/lessons/{$quiz->id}/attempts")->assertRedirect();
        $t1 = Attempt::first();
        $this->actingAs($member)->get("/training/attempts/{$t1->id}")->assertOk()->assertSee('Pick the PPE')->assertDontSee('right answer');
        $this->actingAs($member)->post("/training/attempts/{$t1->id}", ['answers' => [$q1->id => 1, $q2->id => [0]]]);
        $t1->refresh();
        $this->assertSame(0, $t1->score);
        $this->assertFalse($t1->passed);
        $this->actingAs($member)->get("/training/attempts/{$t1->id}")->assertSee('0%')->assertSee('right answer');

        // Attempt 2 passes; the course is complete.
        $this->actingAs($member)->post("/training/a/{$a->id}/lessons/{$quiz->id}/attempts");
        $t2 = Attempt::latest('id')->first();
        $this->assertSame(2, $t2->number);
        $this->actingAs($member)->post("/training/attempts/{$t2->id}", ['answers' => [$q1->id => 0, $q2->id => [1, 0]]]);
        $this->assertSame(100, $t2->fresh()->score);
        $a->refresh();
        $this->assertNotNull($a->completed_at);
        $this->assertSame(100, $a->percent);
        $this->assertSame(100, $a->score);
        $this->assertStringContainsString('attempt 2: 100%', AuditLog::where('action', 'training.attempts.submit')->latest('id')->first()->description);
        $this->assertStringContainsString('Course complete', AuditLog::where('action', 'training.attempts.submit')->latest('id')->first()->description);
    }

    public function test_attempts_run_out_and_a_trainer_can_allow_another(): void
    {
        $member = $this->member();
        $admin = $this->admin();
        $course = $this->course();
        $quiz = $course->lessons->firstWhere('type', 'quiz');
        $a = app(Training::class)->assign($member, $course, $admin);
        foreach ([1, 2] as $n) {
            $this->actingAs($member)->post("/training/a/{$a->id}/lessons/{$quiz->id}/attempts");
            $this->actingAs($member)->post('/training/attempts/'.Attempt::latest('id')->value('id'), ['answers' => []]);
        }
        $this->actingAs($member)->post("/training/a/{$a->id}/lessons/{$quiz->id}/attempts")->assertSessionHas('error');
        $this->assertSame(2, Attempt::count());

        $this->actingAs($admin)->post("/training/assignments/{$a->id}/extra-attempt", ['lesson_id' => $quiz->id])->assertSessionHas('status');
        $this->assertStringContainsString('3 allowed now', AuditLog::where('action', 'training.assignments.extra')->first()->description);
        $this->actingAs($member)->post("/training/a/{$a->id}/lessons/{$quiz->id}/attempts")->assertRedirect();
        $this->assertSame(3, Attempt::count());
    }

    public function test_minimum_time_holds_mark_as_done(): void
    {
        $member = $this->member();
        $course = $this->course();
        $text = $course->lessons->first();
        $text->update(['min_seconds' => 60]);
        $a = app(Training::class)->assign($member, $course, null);
        $this->actingAs($member)->post("/training/a/{$a->id}/lessons/{$text->id}/done")->assertSessionHas('error');
        app(Training::class)->open($a, $text)->update(['seconds_spent' => 61]);
        $this->actingAs($member)->post("/training/a/{$a->id}/lessons/{$text->id}/done")->assertSessionHasNoErrors();
        $this->assertSame(50, $a->fresh()->percent);
    }

    public function test_assigning_and_reassigning_keeps_history(): void
    {
        $admin = $this->admin();
        $one = $this->member('Ann One');
        $two = $this->member('Bo Two');
        $course = $this->course();

        $this->actingAs($admin)->post('/training/assign', ['what' => 'course', 'course_ids' => [$course->id], 'user_ids' => [$one->id, $two->id], 'existing' => 'skip', 'reason' => 'New hire'])
            ->assertSessionHas('status', 'Made 2 assignment(s).');
        $this->actingAs($admin)->post('/training/assign', ['what' => 'course', 'course_ids' => [$course->id], 'user_ids' => [$one->id], 'existing' => 'skip'])
            ->assertSessionHas('status', 'Made 0 assignment(s). Skipped 1 that were already assigned.');

        $first = Assignment::where('user_id', $one->id)->first();
        $first->update(['completed_at' => now(), 'percent' => 100, 'score' => 90]);

        $this->actingAs($admin)->post('/training/assignments/reassign', ['assignment_ids' => [$first->id], 'reason' => 'Annual refresher'])->assertSessionHas('status');
        $this->assertNotNull($first->fresh()->replaced_at);
        $new = Assignment::current()->where('user_id', $one->id)->first();
        $this->assertNotSame($first->id, $new->id);
        $this->assertSame('Annual refresher', $new->reason);
        $this->assertSame(0, $new->percent);
        $this->assertStringContainsString('Ann One: 100%, grade 90%', AuditLog::where('action', 'training.assignments.reassign')->first()->description);

        $this->actingAs($admin)->get("/training/courses/{$course->id}/progress")->assertOk()->assertSee('Earlier times')->assertSee('Ann One');
        $this->actingAs($admin)->get("/training/people/{$one->id}")->assertOk()->assertSee('Annual refresher');
        $this->actingAs($one)->get("/training/a/{$first->id}")->assertOk()->assertSee('assigned again');

        // The old one can't be worked on any more.
        $text = $course->lessons->first();
        $this->actingAs($one)->post("/training/a/{$first->id}/lessons/{$text->id}/done")->assertSessionHas('error');

        // Unassign an unfinished one; finished and earlier ones stay.
        $this->actingAs($admin)->delete("/training/assignments/{$first->id}")->assertSessionHas('error');
        $this->actingAs($admin)->delete("/training/assignments/{$new->id}")->assertSessionHas('status');
        $this->assertNull(Assignment::find($new->id));
    }

    public function test_training_profiles_assign_bundles_and_follow_permission_profiles(): void
    {
        $admin = $this->admin();
        $course = $this->course();
        $second = Course::create(['title' => 'HIPAA', 'status' => 'published']);
        $second->lessons()->create(['title' => 'Privacy', 'type' => 'text', 'position' => 1]);
        $draft = Course::create(['title' => 'Not ready', 'status' => 'draft']);
        $teamMember = PermissionProfile::where('name', 'Team Member')->first();
        $emt = PermissionProfile::create(['name' => 'EMT', 'parent_id' => $teamMember->id, 'permissions' => []]);

        $this->actingAs($admin)->post('/training/profiles', [
            'name' => 'New Hire Orientation', 'due_days' => 30,
            'course_ids' => [$course->id, $second->id, $draft->id], 'order' => [$course->id => 2, $second->id => 1, $draft->id => 3],
            'auto_ids' => [$teamMember->id],
        ])->assertRedirect('/training/profiles');
        $profile = TrainingProfile::first();
        $this->assertSame(['HIPAA', 'Employee Handbook', 'Not ready'], $profile->courses->pluck('title')->all());
        $this->assertStringContainsString('automatic for: Team Member', AuditLog::where('action', 'training.profiles.store')->first()->description);

        // A new EMT (sub-profile of Team Member) gets the published courses automatically, due in 30 days.
        $this->actingAs($admin)->post('/admin/users', ['name' => 'Nina New', 'email' => 'nina@example.com', 'permission_profile_id' => $emt->id]);
        $nina = User::where('email', 'nina@example.com')->first();
        $this->assertSame(2, Assignment::where('user_id', $nina->id)->count());
        $this->assertSame(today()->addDays(30)->toDateString(), Assignment::where('user_id', $nina->id)->first()->due_on->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'training.auto-assign', 'user_id' => $admin->id]);

        // A dispatcher doesn't; moving them onto Team Member does, once.
        $dispatcher = User::factory()->role('dispatcher')->create();
        $this->assertSame(0, Assignment::where('user_id', $dispatcher->id)->count());
        $dispatcher->update(['permission_profile_id' => $teamMember->id]);
        $dispatcher->update(['name' => 'Renamed']);
        $this->assertSame(2, Assignment::where('user_id', $dispatcher->id)->count());

        // Giving it now to existing Team Members skips what they already have.
        $existing = User::factory()->role('employee')->create();
        Assignment::where('user_id', $existing->id)->delete();
        $this->actingAs($admin)->post("/training/profiles/{$profile->id}/apply")->assertSessionHas('status');
        $this->assertSame(2, Assignment::where('user_id', $existing->id)->count());
        $this->assertSame(2, Assignment::where('user_id', $nina->id)->count());

        // Assigning by hand from the Assign page.
        $loner = User::factory()->role('dispatcher')->create();
        $this->actingAs($admin)->post('/training/assign', ['what' => 'profile', 'training_profile_id' => $profile->id, 'user_ids' => [$loner->id], 'existing' => 'skip'])
            ->assertSessionHas('status', 'Made 2 assignment(s).');
        $this->assertSame($profile->id, Assignment::where('user_id', $loner->id)->first()->training_profile_id);
    }

    public function test_reports_show_progress_and_export_csv(): void
    {
        $admin = $this->admin();
        $member = $this->member('Casey Report');
        $course = $this->course();
        $a = app(Training::class)->assign($member, $course, $admin, ['due_on' => today()->subDay()]);
        $a->update(['seconds_spent' => 600, 'percent' => 50]);

        $this->actingAs($admin)->get('/training/reports?status=overdue')->assertOk()->assertSee('Casey Report')->assertSee('Overdue');
        $csv = $this->actingAs($admin)->get('/training/reports.csv?course='.$course->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('"Casey Report"', $csv);
        $this->assertMatchesRegularExpression('/Overdue,50,\d+,10,/', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'training.reports.csv']);
        $this->actingAs($admin)->get('/training/reports/attempts.csv')->assertOk();
        $this->actingAs($member)->get('/training/reports')->assertForbidden();
    }

    public function test_files_need_the_course_or_a_signed_link(): void
    {
        Storage::fake('local');
        $member = $this->member();
        $course = $this->course();
        $lesson = $course->lessons()->create(['title' => 'Video', 'type' => 'video', 'position' => 3, 'file_path' => 'training/1/v.mp4', 'file_name' => 'v.mp4', 'file_mime' => 'video/mp4']);
        Storage::disk('local')->put('training/1/v.mp4', 'video');

        $this->actingAs($member)->get("/training/lessons/{$lesson->id}/file")->assertForbidden();
        app(Training::class)->assign($member, $course, null);
        $this->actingAs($member)->get("/training/lessons/{$lesson->id}/file")->assertOk()->assertHeader('Content-Type', 'video/mp4');

        auth()->logout();
        $this->get("/training/f/{$lesson->id}")->assertForbidden();
        $this->get($lesson->signedFileUrl())->assertOk();
    }

    public function test_the_api_runs_a_course_for_phone_apps(): void
    {
        $member = $this->member();
        $course = $this->course();
        [$text, $quiz] = $course->lessons->all();
        $a = app(Training::class)->assign($member, $course, null);
        $headers = ['Authorization' => 'Bearer '.$member->createToken('iPhone', ['wellness', 'training'])->plainTextToken];

        $this->getJson('/api/v1/training/assignments', $headers)->assertOk()->assertJsonPath('assignments.0.course.title', 'Employee Handbook');
        $this->getJson("/api/v1/training/assignments/{$a->id}", $headers)->assertOk()
            ->assertJsonPath('lessons.0.html', '<p>Hello</p>')->assertJsonPath('lessons.1.quiz.allowed', 2);
        $this->postJson("/api/v1/training/assignments/{$a->id}/lessons/{$text->id}/time", ['seconds' => 30], $headers)->assertJson(['added' => 30]);
        $this->postJson("/api/v1/training/assignments/{$a->id}/lessons/{$text->id}/done", [], $headers)->assertOk()->assertJsonPath('assignment.percent', 50);

        $start = $this->postJson("/api/v1/training/assignments/{$a->id}/lessons/{$quiz->id}/attempts", [], $headers)->assertCreated();
        $this->assertArrayNotHasKey('correct', $start->json('questions.0.choices.0'));
        $ids = collect($start->json('questions'))->pluck('id');
        $answers = [$ids[0] => 0, $ids[1] => [0, 1]];
        if ($start->json('questions.0.prompt') !== 'Who do you call?') {
            $answers = [$ids[1] => 0, $ids[0] => [0, 1]];
        }
        $this->postJson('/api/v1/training/attempts/'.$start->json('attempt.id'), ['answers' => $answers], $headers)
            ->assertOk()->assertJsonPath('passed', true)->assertJsonPath('assignment.status', 'completed');
        $this->assertDatabaseHas('audit_logs', ['action' => 'api.training.attempts.submit']);

        // A token from before training was added to the app can't use it.
        $old = ['Authorization' => 'Bearer '.$member->createToken('Old', ['wellness'])->plainTextToken];
        auth()->forgetGuards();
        $this->getJson('/api/v1/training/assignments', $old)->assertForbidden();
    }

    public function test_expected_time_is_worked_out_from_the_content(): void
    {
        Storage::fake('local');
        $course = Course::create(['title' => 'Timing', 'status' => 'draft']);
        $text = $course->lessons()->create(['title' => 'Read', 'type' => 'text', 'position' => 1, 'body' => '<p>'.str_repeat('word ', 400).'</p><img src="/training/images/x.png">']);
        $this->assertSame(132, $text->est_seconds);
        $this->assertSame('400 words', $text->est_basis);

        // A PDF counts its pages.
        Storage::disk('local')->put('training/p.pdf', "%PDF-1.4\n1 0 obj << /Type /Page >>\n2 0 obj << /Type /Page >>\n3 0 obj << /Type /Pages /Count 2 >>");
        $pdf = $course->lessons()->create(['title' => 'Policy', 'type' => 'pdf', 'position' => 2, 'file_path' => 'training/p.pdf', 'file_name' => 'p.pdf']);
        $this->assertSame(240, $pdf->est_seconds);
        $this->assertSame('2 pages', $pdf->est_basis);

        // A PowerPoint counts its slides.
        $zip = new \ZipArchive;
        $path = Storage::disk('local')->path('training/deck.pptx');
        $zip->open($path, \ZipArchive::CREATE);
        foreach ([1, 2, 3] as $n) {
            $zip->addFromString("ppt/slides/slide{$n}.xml", '<x/>');
        }
        $zip->close();
        $slides = $course->lessons()->create(['title' => 'Deck', 'type' => 'slides', 'position' => 3, 'file_path' => 'training/deck.pptx', 'file_name' => 'deck.pptx', 'file_size' => filesize($path)]);
        $this->assertSame(180, $slides->est_seconds);

        // A video reads its running time from the MP4 header (here 2 minutes), plus 10%.
        $mvhd = "\0\0\0\0".str_repeat("\0", 8).pack('N', 1000).pack('N', 120000).str_repeat("\0", 80);
        $mp4 = pack('N', 16).'ftypisom'."\0\0\0\0".pack('N', 8 + 8 + strlen($mvhd)).'moov'.pack('N', 8 + strlen($mvhd)).'mvhd'.$mvhd;
        Storage::disk('local')->put('training/v.mp4', $mp4);
        $video = $course->lessons()->create(['title' => 'Video', 'type' => 'video', 'position' => 4, 'file_path' => 'training/v.mp4', 'file_name' => 'v.mp4', 'file_size' => strlen($mp4)]);
        $this->assertSame(132, $video->est_seconds);
        $this->assertSame('2:00 video', $video->est_basis);

        // A quiz grows with its questions; a minimum time wins when longer.
        $quiz = $course->lessons()->create(['title' => 'Quiz', 'type' => 'quiz', 'position' => 5]);
        $quiz->questions()->create(['type' => 'single', 'prompt' => 'A?', 'choices' => [['text' => 'x', 'correct' => true], ['text' => 'y', 'correct' => false]]]);
        $quiz->questions()->create(['type' => 'multiple', 'prompt' => 'B?', 'choices' => [['text' => 'x', 'correct' => true], ['text' => 'y', 'correct' => true]]]);
        $this->assertSame(120, $quiz->fresh()->est_seconds);
        $link = $course->lessons()->create(['title' => 'Link', 'type' => 'link', 'position' => 6, 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'min_seconds' => 600]);
        $this->assertSame(600, $link->est_seconds);

        $this->assertSame(132 + 240 + 180 + 132 + 120 + 600, $course->estimatedSeconds());
        $this->assertSame('About 23 min', $course->estimateLabel());
        $this->actingAs($this->admin())->get('/training/courses')->assertSee('About 23 min');
    }

    public function test_finished_courses_are_searchable_reference_material(): void
    {
        $member = $this->member();
        $course = $this->course();
        $course->lessons()->create(['title' => 'Stair chairs', 'type' => 'text', 'position' => 3, 'body' => '<p>Always lock the <strong>stair chair</strong> before tilting it back.</p>']);
        $other = Course::create(['title' => 'Not finished', 'status' => 'published']);
        $other->lessons()->create(['title' => 'Secret', 'type' => 'text', 'position' => 1, 'body' => '<p>The stair chair lives in the truck.</p>']);
        $training = app(Training::class);
        $a = $training->assign($member, $course, null);
        $a->update(['completed_at' => now(), 'percent' => 100]);
        $training->assign($member, $other, null);

        $this->actingAs($member)->get('/training?q=stair+tilting')->assertOk()
            ->assertSee('1 match')->assertSee('Stair chairs')->assertSee('<mark>tilting</mark>', false)->assertDontSee('Secret');
        $this->actingAs($member)->get('/training?q=handbook')->assertSee('Welcome');
        // Quiz questions aren't searched.
        $this->actingAs($member)->get('/training?q=PPE')->assertSee('0 matches');
        $this->actingAs($member)->get('/training?q=%25')->assertOk();

        // The lesson still opens after the course is done, with no time counting.
        $lesson = $course->lessons()->where('title', 'Stair chairs')->first();
        $this->actingAs($member)->get("/training/a/{$a->id}/lessons/{$lesson->id}")->assertOk()->assertSee('lock the')->assertDontSee('Mark as done');

        // Someone who hasn't finished it finds nothing.
        $this->actingAs($this->member('Someone Else'))->get('/training?q=stair')->assertDontSee('Stair chairs');
    }

    public function test_courses_have_named_sections_that_group_lessons(): void
    {
        $admin = $this->admin();
        $course = $this->course();
        $this->actingAs($admin)->post("/training/courses/{$course->id}/sections", ['title' => 'Regulations'])->assertRedirect();
        $this->actingAs($admin)->post("/training/courses/{$course->id}/sections", ['title' => 'Policies'])->assertRedirect();
        [$regs, $pols] = $course->sections()->get()->all();
        $this->assertSame([1, 2], [$regs->position, $pols->position]);
        $this->assertStringContainsString('Added the section "Regulations"', AuditLog::where('action', 'training.sections.store')->first()->description);

        // New lessons go at the end of their section; lessons outside any section stay first.
        $this->actingAs($admin)->post("/training/courses/{$course->id}/lessons", ['title' => 'Hours of service', 'type' => 'text', 'section_id' => $regs->id]);
        $this->actingAs($admin)->post("/training/courses/{$course->id}/lessons", ['title' => 'Phones', 'type' => 'text', 'section_id' => $pols->id]);
        $this->actingAs($admin)->post("/training/courses/{$course->id}/lessons", ['title' => 'Speed limits', 'type' => 'text', 'section_id' => $regs->id]);
        $order = fn () => $course->lessons()->get()->map(fn ($l) => $l->title.'@'.($l->section?->title ?? '-'))->all();
        $this->assertSame(['Welcome@-', 'Handbook quiz@-', 'Hours of service@Regulations', 'Speed limits@Regulations', 'Phones@Policies'], $order());
        $this->assertSame([1, 2, 3, 4, 5], $course->lessons()->pluck('position')->all());

        // Another course's section can't be used.
        $other = Course::create(['title' => 'Other'])->sections()->create(['title' => 'Elsewhere', 'position' => 1]);
        $this->actingAs($admin)->post("/training/courses/{$course->id}/lessons", ['title' => 'Nope', 'type' => 'text', 'section_id' => $other->id])->assertSessionHasErrors('section_id');

        // Lessons move up and down inside their section only.
        $speed = Lesson::where('title', 'Speed limits')->first();
        $this->actingAs($admin)->post("/training/lessons/{$speed->id}/move", ['direction' => 'up']);
        $this->actingAs($admin)->post("/training/lessons/{$speed->id}/move", ['direction' => 'up']);
        $this->assertSame(['Welcome@-', 'Handbook quiz@-', 'Speed limits@Regulations', 'Hours of service@Regulations', 'Phones@Policies'], $order());

        // Moving a lesson to another section from its edit page puts it at the end there, and the audit log says so.
        $quiz = Lesson::where('title', 'Handbook quiz')->first();
        $this->actingAs($admin)->put("/training/lessons/{$quiz->id}", ['title' => 'Handbook quiz', 'passing_score' => 50, 'max_attempts' => 2, 'section_id' => $pols->id])->assertSessionHas('status');
        $this->assertSame(['Welcome@-', 'Speed limits@Regulations', 'Hours of service@Regulations', 'Phones@Policies', 'Handbook quiz@Policies'], $order());
        $this->assertStringContainsString('section from none to Policies', AuditLog::where('action', 'training.lessons.update')->latest('id')->first()->description);

        // Sections reorder (their lessons go with them), rename, and show to Team Members.
        $this->actingAs($admin)->post("/training/sections/{$pols->id}/move", ['direction' => 'up']);
        $this->assertSame(['Welcome@-', 'Phones@Policies', 'Handbook quiz@Policies', 'Speed limits@Regulations', 'Hours of service@Regulations'], $order());
        $this->actingAs($admin)->put("/training/sections/{$regs->id}", ['title' => 'Rules of the road']);
        $this->assertStringContainsString('name from Regulations to Rules of the road', AuditLog::where('action', 'training.sections.update')->first()->description);
        $this->actingAs($admin)->get("/training/courses/{$course->id}/edit")->assertOk()->assertSeeInOrder(['Welcome', 'Policies', 'Phones', 'Rules of the road', 'Speed limits']);
        // The lesson designer lists the whole course on the left to jump between lessons, with a quick add.
        $this->actingAs($admin)->get('/training/lessons/'.Lesson::where('title', 'Phones')->value('id').'/edit')->assertOk()
            ->assertSeeInOrder(['builder-nav', 'Welcome', 'Policies', 'Phones', 'Rules of the road', 'Speed limits', 'Hours of service', 'Add a lesson'])
            ->assertSee('aria-current="page"', false);

        $member = $this->member();
        $a = app(Training::class)->assign($member, $course->fresh(), $admin);
        $this->actingAs($member)->get("/training/a/{$a->id}")->assertOk()->assertSeeInOrder(['Welcome', 'Policies', '0 of 2 done', 'Phones', 'Rules of the road', 'Speed limits']);
        $this->actingAs($member)->get("/training/a/{$a->id}/lessons/".Lesson::where('title', 'Phones')->value('id'))->assertOk()->assertSee('Policies · Lesson 2 of 5');
        $token = $member->createToken('app', ['training'])->plainTextToken;
        $json = $this->withToken($token)->getJson("/api/v1/training/assignments/{$a->id}")->assertOk()->json();
        $this->assertSame(['Policies', 'Rules of the road'], array_column($json['sections'], 'title'));
        $this->assertSame([null, 'Policies', 'Policies', 'Rules of the road', 'Rules of the road'], array_column($json['lessons'], 'section'));

        // Removing a section keeps its lessons, outside any section.
        $this->actingAs($admin)->delete("/training/sections/{$pols->id}")->assertRedirect();
        $this->assertNull(Section::find($pols->id));
        $this->assertSame(5, $course->lessons()->count());
        $this->assertSame([1, 2, 3, 4, 5], $course->lessons()->pluck('position')->all());
        $this->assertStringContainsString('its 2 lessons stay in the course without a section', AuditLog::where('action', 'training.sections.destroy')->first()->description);
    }
}
