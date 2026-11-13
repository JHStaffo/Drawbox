<?php

namespace App\Training\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Training\Models\Assignment;
use App\Training\Models\Attempt;
use App\Training\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Training > Reports: who has what, completion %, time spent, grades and attempts, with CSV. */
class ReportController extends Controller
{
    public function index(Request $request)
    {
        [$filters, $rows] = $this->rows($request);

        return view('training.reports', [
            'filters' => $filters,
            'rows' => $rows,
            'courses' => Course::orderBy('title')->get(),
            'users' => User::byLastName(User::all()),
            'permissionProfiles' => PermissionProfile::ordered(),
            'summary' => [
                'assigned' => $rows->count(),
                'completed' => $rows->whereNotNull('completed_at')->count(),
                'overdue' => $rows->filter(fn ($a) => $a->status() === 'overdue')->count(),
                'seconds' => $rows->sum('seconds_spent'),
                'avgScore' => $rows->whereNotNull('score')->avg('score'),
            ],
        ]);
    }

    public function csv(Request $request)
    {
        [, $rows] = $this->rows($request);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Team Member', 'Email', 'Permission profile', 'Course', 'Status', 'Completion %', 'Expected minutes', 'Minutes spent', 'Quiz grade %', 'Quiz attempts', 'Assigned', 'Assigned by', 'Training profile', 'Reason', 'Due', 'Started', 'Completed', 'Reassigned']);
            foreach ($rows as $a) {
                fputcsv($out, [
                    $a->user?->name, $a->user?->email, $a->user?->profile?->label(), $a->course?->title, $a->statusLabel(), $a->percent,
                    round($a->course->estimatedSeconds() / 60), round($a->seconds_spent / 60, 1), $a->score, $a->attempts_count, $a->created_at->format('Y-m-d'), $a->assigner?->name ?? 'Automatic',
                    $a->trainingProfile?->name, $a->reason, $a->due_on?->format('Y-m-d'), $a->started_at?->format('Y-m-d H:i'),
                    $a->completed_at?->format('Y-m-d H:i'), $a->replaced_at?->format('Y-m-d H:i'),
                ]);
            }
            fclose($out);
        }, 'training-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Every submitted quiz attempt in the report's filter, one row each. */
    public function attemptsCsv(Request $request)
    {
        [, $rows] = $this->rows($request);
        $attempts = Attempt::with('lesson', 'user', 'assignment.course')->whereIn('assignment_id', $rows->pluck('id'))->whereNotNull('submitted_at')->orderBy('submitted_at')->get();

        return response()->streamDownload(function () use ($attempts) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Team Member', 'Course', 'Quiz', 'Attempt', 'Score %', 'Right', 'Questions', 'Passed', 'Minutes', 'Submitted']);
            foreach ($attempts as $t) {
                fputcsv($out, [$t->user?->name, $t->assignment?->course?->title, $t->lesson?->title, $t->number, $t->score, $t->correct, $t->total, $t->passed ? 'Yes' : 'No', round($t->seconds / 60, 1), $t->submitted_at->format('Y-m-d H:i')]);
            }
            fclose($out);
        }, 'quiz-attempts-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array{0: array, 1: Collection<int, Assignment>} */
    private function rows(Request $request): array
    {
        $filters = $request->validate([
            'course' => 'nullable|integer',
            'user' => 'nullable|integer',
            'profile' => 'nullable|integer',
            'status' => 'nullable|in:open,not_started,in_progress,overdue,completed,history',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]) + ['course' => null, 'user' => null, 'profile' => null, 'status' => null, 'from' => null, 'to' => null];

        $query = Assignment::with('user.profile.parent', 'course.lessons', 'assigner', 'trainingProfile')->withCount(['attempts' => fn ($q) => $q->whereNotNull('submitted_at')]);
        $filters['status'] === 'history' ? $query->whereNotNull('replaced_at') : $query->whereNull('replaced_at');
        if ($filters['course']) {
            $query->where('course_id', $filters['course']);
        }
        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }
        if ($filters['profile']) {
            $ids = PermissionProfile::where('id', $filters['profile'])->orWhere('parent_id', $filters['profile'])->pluck('id');
            $query->whereHas('user', fn ($q) => $q->whereIn('permission_profile_id', $ids));
        }
        if ($filters['from']) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if ($filters['to']) {
            $query->where('created_at', '<', \Carbon\Carbon::parse($filters['to'])->addDay());
        }
        $rows = $query->get()->filter(fn ($a) => $a->user && $a->course);
        $rows = match ($filters['status']) {
            'open' => $rows->filter(fn ($a) => $a->isOpen()),
            'not_started', 'in_progress', 'overdue', 'completed' => $rows->filter(fn ($a) => $a->status() === $filters['status']),
            default => $rows,
        };

        return [$filters, $rows->sortBy(fn ($a) => [$a->user->sortName(), mb_strtolower($a->course->title)])->values()];
    }
}
