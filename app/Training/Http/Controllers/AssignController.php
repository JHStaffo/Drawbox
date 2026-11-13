<?php

namespace App\Training\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Training\Models\Assignment;
use App\Training\Models\Course;
use App\Training\Models\LessonProgress;
use App\Training\Models\TrainingProfile;
use App\Training\Training;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Training > Assign: give courses or training profiles, reassign, allow another quiz attempt, and training profiles. */
class AssignController extends Controller
{
    public function create(Request $request)
    {
        return view('training.assign', [
            'courses' => Course::where('status', 'published')->orderBy('title')->get(),
            'profiles' => TrainingProfile::with('courses')->orderBy('name')->get(),
            'users' => User::byLastName(User::with('profile.parent')->where('active', true)->get()),
            'permissionProfiles' => PermissionProfile::ordered(),
            'pick' => ['course' => $request->integer('course') ?: null, 'profile' => $request->integer('profile') ?: null],
        ]);
    }

    public function store(Request $request, Training $training)
    {
        $data = $request->validate([
            'what' => 'required|in:course,profile',
            'course_ids' => 'required_if:what,course|array',
            'course_ids.*' => ['integer', Rule::exists('training_courses', 'id')->where('status', 'published')],
            'training_profile_id' => 'required_if:what,profile|nullable|exists:training_profiles,id',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
            'permission_profile_ids' => 'nullable|array',
            'permission_profile_ids.*' => 'integer|exists:permission_profiles,id',
            'due_on' => 'nullable|date|after_or_equal:today',
            'reason' => 'nullable|string|max:255',
            'existing' => 'required|in:skip,reassign',
        ], [
            'course_ids.required_if' => 'Pick at least one course.',
            'training_profile_id.required_if' => 'Pick a training profile.',
        ]);

        $users = User::whereIn('id', $data['user_ids'] ?? [])->where('active', true)->get();
        if (! empty($data['permission_profile_ids'])) {
            $profileIds = PermissionProfile::whereIn('id', $data['permission_profile_ids'])->orWhereIn('parent_id', $data['permission_profile_ids'])->pluck('id');
            $users = $users->merge(User::where('active', true)->whereIn('permission_profile_id', $profileIds)->get())->unique('id');
        }
        if ($users->isEmpty()) {
            return back()->withInput()->with('error', 'Pick at least one Team Member or permission profile.');
        }

        $options = ['reassign' => $data['existing'] === 'reassign', 'due_on' => $data['due_on'] ?? null, 'reason' => $data['reason'] ?? null];
        $by = $request->user();
        $assigned = 0;
        $skipped = 0;
        DB::transaction(function () use ($data, $users, $options, $by, $training, &$assigned, &$skipped) {
            if ($data['what'] === 'profile') {
                $profile = TrainingProfile::with('courses')->findOrFail($data['training_profile_id']);
                foreach ($users as $user) {
                    $counts = $training->assignProfile($user, $profile, $by, array_filter($options, fn ($v) => $v !== null));
                    $assigned += $counts['assigned'];
                    $skipped += $counts['skipped'];
                }
            } else {
                foreach (Course::whereIn('id', $data['course_ids'])->get() as $course) {
                    foreach ($users as $user) {
                        $training->assign($user, $course, $by, $options) ? $assigned++ : $skipped++;
                    }
                }
            }
        });

        $what = $data['what'] === 'profile'
            ? 'the '.TrainingProfile::find($data['training_profile_id'])->name.' training profile'
            : Course::whereIn('id', $data['course_ids'])->pluck('title')->implode(', ');
        $names = $users->count() <= 10 ? User::byLastName($users)->pluck('name')->implode(', ') : $users->count().' Team Members';
        audit_note("Assigned {$what} to {$names}: {$assigned} assignment(s) made".($skipped ? ", {$skipped} skipped because they already had it" : '')
            .($options['reassign'] ? ' (reassigning where they already had it)' : '')
            .($options['due_on'] ? ", due {$options['due_on']}" : '').($options['reason'] ? ", reason: {$options['reason']}" : '').'.');

        return redirect()->route('training.assign')->with('status', "Made {$assigned} assignment(s)".($skipped ? ". Skipped {$skipped} that were already assigned." : '.'));
    }

    /** Everyone who has a course, with progress, time, grade and attempts. */
    public function progress(Request $request, Course $course)
    {
        abort_unless($request->user()->hasAnyPermission('training_assign', 'training_reports'), 403);
        $assignments = Assignment::with('user', 'assigner', 'trainingProfile')->where('course_id', $course->id)->latest('id')->get();
        $quizzes = $course->lessons->where('type', 'quiz')->values();
        $quizProgress = LessonProgress::whereIn('assignment_id', $assignments->pluck('id'))->whereIn('lesson_id', $quizzes->pluck('id'))->get()->groupBy('assignment_id');
        $attempts = DB::table('training_attempts')->whereIn('assignment_id', $assignments->pluck('id'))->whereNotNull('submitted_at')
            ->selectRaw('assignment_id, lesson_id, COUNT(*) as n')->groupBy('assignment_id', 'lesson_id')->get()->groupBy('assignment_id');

        return view('training.progress', [
            'course' => $course,
            'current' => $assignments->whereNull('replaced_at')->filter(fn ($a) => $a->user)->sortBy(fn ($a) => [$a->completed_at !== null, $a->user->sortName()])->values(),
            'history' => $assignments->whereNotNull('replaced_at')->filter(fn ($a) => $a->user)->values(),
            'quizzes' => $quizzes,
            'quizProgress' => $quizProgress,
            'attempts' => $attempts,
            'canAssign' => $request->user()->hasPermission('training_assign'),
        ]);
    }

    /** Reassign so they do it again: the old assignment stays as history. */
    public function reassign(Request $request, Training $training)
    {
        $data = $request->validate([
            'assignment_ids' => 'required|array|min:1',
            'assignment_ids.*' => 'integer|exists:training_assignments,id',
            'due_on' => 'nullable|date|after_or_equal:today',
            'reason' => 'nullable|string|max:255',
        ], ['assignment_ids.required' => 'Tick at least one Team Member to reassign.']);
        $old = Assignment::with('user', 'course')->current()->whereIn('id', $data['assignment_ids'])->get()->filter(fn ($a) => $a->user);
        DB::transaction(function () use ($old, $request, $training, $data) {
            foreach ($old as $a) {
                $training->assign($a->user, $a->course, $request->user(), [
                    'reassign' => true, 'due_on' => $data['due_on'] ?? null, 'reason' => $data['reason'] ?? null, 'training_profile_id' => $a->training_profile_id,
                ]);
            }
        });
        $course = $old->first()?->course;
        audit_note('Reassigned '.($course?->title ?? 'a course').' to '.User::byLastName($old->pluck('user'))->pluck('name')->implode(', ').' so they do it again'
            .(! empty($data['due_on']) ? ", due {$data['due_on']}" : '').(! empty($data['reason']) ? ", reason: {$data['reason']}" : '')
            .'. Earlier results stay as history ('.$old->map(fn ($a) => "{$a->user->name}: {$a->percent}%".($a->score !== null ? ", grade {$a->score}%" : ''))->implode('; ').').');

        return back()->with('status', 'Reassigned to '.$old->count().' Team Member(s).');
    }

    public function update(Request $request, Assignment $assignment)
    {
        $data = $request->validate(['due_on' => 'nullable|date']);
        $from = $assignment->due_on?->toDateString() ?? 'none';
        $assignment->update(['due_on' => $data['due_on'] ?? null]);
        audit_note("Changed the due date of {$assignment->course->title} for {$assignment->user->name} from {$from} to ".($data['due_on'] ?? 'none').'.');

        return back()->with('status', 'Due date saved.');
    }

    /** One more try at a quiz after they've used every attempt. */
    public function extraAttempt(Request $request, Assignment $assignment, Training $training)
    {
        $lesson = $assignment->course->lessons()->where('type', 'quiz')->findOrFail($request->integer('lesson_id'));
        $progress = $training->open($assignment, $lesson);
        $progress->increment('extra_attempts');
        audit_note("Allowed {$assignment->user->name} another attempt at the quiz \"{$lesson->title}\" in {$assignment->course->title} (".($lesson->max_attempts + $progress->extra_attempts).' allowed now).');

        return back()->with('status', "{$assignment->user->name} can try {$lesson->title} again.");
    }

    /** Take back an unfinished assignment given by mistake. Finished ones stay on record. */
    public function destroy(Assignment $assignment)
    {
        if ($assignment->completed_at || $assignment->replaced_at) {
            return back()->with('error', 'Finished and earlier assignments stay on record.');
        }
        $name = $assignment->user->name;
        $title = $assignment->course->title;
        $assignment->delete();
        audit_note("Unassigned {$title} from {$name} (it was {$assignment->percent}% done, ".$assignment->timeLabel().' spent).');

        return back()->with('status', "Unassigned {$title} from {$name}.");
    }

    /** One Team Member's training: every course, every time, and every quiz attempt. */
    public function person(Request $request, User $user)
    {
        abort_unless($request->user()->hasAnyPermission('training_assign', 'training_reports'), 403);

        return view('training.person', [
            'person' => $user,
            'assignments' => Assignment::with('course', 'assigner', 'trainingProfile', 'attempts.lesson')->where('user_id', $user->id)->latest('id')->get(),
            'canAssign' => $request->user()->hasPermission('training_assign'),
        ]);
    }
}
