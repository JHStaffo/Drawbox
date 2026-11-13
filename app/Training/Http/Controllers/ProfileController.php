<?php

namespace App\Training\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PermissionProfile;
use App\Training\Models\Course;
use App\Training\Models\TrainingProfile;
use App\Training\Training;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Training > Training profiles: bundles of courses, optionally given automatically by permission profile. */
class ProfileController extends Controller
{
    public function index()
    {
        return view('training.profiles.index', [
            'profiles' => TrainingProfile::with('courses', 'autoProfiles.parent')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return $this->form(new TrainingProfile);
    }

    public function edit(TrainingProfile $profile)
    {
        return $this->form($profile->load('courses', 'autoProfiles'));
    }

    public function store(Request $request)
    {
        $profile = DB::transaction(function () use ($request) {
            $data = $this->validated($request);
            $profile = TrainingProfile::create($data['fields']);
            $this->sync($profile, $data);

            return $profile;
        });
        audit_note('Added the training profile '.$this->describe($profile).'.');

        return redirect()->route('training.profiles')->with('status', "Added {$profile->name}.");
    }

    public function update(Request $request, TrainingProfile $profile)
    {
        $before = $this->describe($profile->load('courses', 'autoProfiles'));
        DB::transaction(function () use ($request, $profile) {
            $data = $this->validated($request, $profile);
            $profile->update($data['fields']);
            $this->sync($profile, $data);
        });
        $after = $this->describe($profile->fresh(['courses', 'autoProfiles']));
        audit_note("Changed a training profile from {$before} to {$after}.");

        return redirect()->route('training.profiles')->with('status', "Saved {$profile->name}. Team Members who already have it keep what they were given; use Assign to give them new courses.");
    }

    public function destroy(TrainingProfile $profile)
    {
        $name = $profile->name;
        $profile->delete();
        audit_note("Deleted the training profile {$name}. Courses already assigned through it stay assigned.");

        return redirect()->route('training.profiles')->with('status', "Deleted {$name}.");
    }

    /** Give it now to everyone on its automatic permission profiles who doesn't have its courses yet. */
    public function apply(Request $request, TrainingProfile $profile, Training $training)
    {
        $profile->load('courses', 'autoProfiles');
        $users = $profile->autoUsers();
        $assigned = 0;
        DB::transaction(function () use ($users, $profile, $training, $request, &$assigned) {
            foreach ($users as $user) {
                $assigned += $training->assignProfile($user, $profile, $request->user())['assigned'];
            }
        });
        audit_note("Gave the {$profile->name} training profile to everyone on its permission profiles now: {$assigned} assignment(s) for {$users->count()} Team Member(s).");

        return back()->with('status', "Made {$assigned} assignment(s). Courses people already had were left alone.");
    }

    private function form(TrainingProfile $profile)
    {
        $chosen = $profile->exists ? $profile->courses->pluck('id')->all() : [];
        $courses = Course::where('status', '!=', 'archived')->orWhereIn('id', $chosen)->orderBy('title')->get()
            ->sortBy(fn ($c) => [in_array($c->id, $chosen) ? array_search($c->id, $chosen) : 1000, mb_strtolower($c->title)])->values();

        return view('training.profiles.form', [
            'profile' => $profile,
            'courses' => $courses,
            'chosen' => $chosen,
            'permissionProfiles' => PermissionProfile::ordered(),
            'auto' => $profile->exists ? $profile->autoProfiles->pluck('id')->all() : [],
        ]);
    }

    private function validated(Request $request, ?TrainingProfile $profile = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('training_profiles', 'name')->ignore($profile?->id)],
            'description' => 'nullable|string|max:2000',
            'due_days' => 'nullable|integer|min:1|max:3650',
            'course_ids' => 'required|array|min:1',
            'course_ids.*' => 'integer|exists:training_courses,id',
            'order' => 'nullable|array',
            'order.*' => 'nullable|integer|min:0|max:999',
            'auto_ids' => 'nullable|array',
            'auto_ids.*' => 'integer|exists:permission_profiles,id',
        ], ['course_ids.required' => 'Tick at least one course.']);

        return [
            'fields' => ['name' => $data['name'], 'description' => $data['description'] ?? null, 'due_days' => $data['due_days'] ?? null],
            'courses' => collect($data['course_ids'])->map('intval')->unique()->sortBy(fn ($id) => [(int) ($data['order'][$id] ?? 999), $id])->values()->all(),
            'auto' => array_map('intval', $data['auto_ids'] ?? []),
        ];
    }

    private function sync(TrainingProfile $profile, array $data): void
    {
        $profile->courses()->sync(collect($data['courses'])->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i + 1]])->all());
        $profile->autoProfiles()->sync($data['auto']);
    }

    private function describe(TrainingProfile $profile): string
    {
        $auto = $profile->autoProfiles->map(fn ($p) => $p->label())->implode(', ');

        return "\"{$profile->name}\" (courses: ".($profile->courses->pluck('title')->implode(', ') ?: 'none')
            .'; due in '.($profile->due_days ? "{$profile->due_days} days" : 'each course\'s own days')
            .'; automatic for: '.($auto ?: 'nobody').')';
    }
}
