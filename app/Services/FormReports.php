<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\PermissionProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Form submissions filtered by date, form, team member, position and sender, and totaled any of several ways. */
class FormReports
{
    public const PERIODS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'week' => 'This week',
        'last_week' => 'Last week',
        'month' => 'This month',
        'last_month' => 'Last month',
        'year' => 'This year',
        'custom' => 'Custom dates',
    ];

    public const GROUPS = [
        'member' => 'Team Member',
        'position' => 'Position',
        'sender' => 'Sent by',
        'form' => 'Form',
        'day' => 'Day',
        'week' => 'Week',
        'month' => 'Month',
    ];

    /** The checked filters, with the date range worked out. */
    public function filters(Request $request): array
    {
        $f = $request->validate([
            'period' => 'nullable|in:'.implode(',', array_keys(self::PERIODS)),
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'form' => 'nullable|integer',
            'member' => 'nullable|integer',
            'position' => 'nullable|integer',
            'sender' => 'nullable|integer',
            'group' => 'nullable|string|max:250',
        ]);
        $f['period'] ??= ($request->filled('from') || $request->filled('to')) ? 'custom' : 'month';
        [$f['start'], $f['end']] = $this->range($f['period'], $f['from'] ?? null, $f['to'] ?? null);
        $f['group'] ??= 'member';

        return $f;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} Weeks run Monday to Sunday. */
    public function range(string $period, ?string $from = null, ?string $to = null): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'week' => [$now->startOfWeek(), $now->endOfWeek()],
            'last_week' => [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'year' => [$now->startOfYear(), $now->endOfYear()],
            'custom' => [
                ($from ? CarbonImmutable::parse($from) : $now->startOfMonth())->startOfDay(),
                ($to ? CarbonImmutable::parse($to) : $now)->endOfDay(),
            ],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };
    }

    /** Submissions matching the filters, newest first. */
    public function submissions(array $f): Collection
    {
        $positions = null;
        if (! empty($f['position'])) {
            // A main profile includes its sub-profiles (Team Member covers EMT, Paramedic ...).
            $positions = PermissionProfile::where('id', $f['position'])->orWhere('parent_id', $f['position'])->pluck('id');
        }

        return FormSubmission::with('user', 'subject.profile.parent', 'form')
            ->whereBetween('created_at', [$f['start'], $f['end']])
            ->when($f['form'] ?? null, fn ($q, $id) => $q->where('form_id', $id))
            ->when($f['member'] ?? null, fn ($q, $id) => $q->where('subject_user_id', $id))
            ->when($f['sender'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($positions, fn ($q) => $q->whereHas('subject', fn ($u) => $u->whereIn('permission_profile_id', $positions)))
            ->latest()
            ->latest('id')
            ->get();
    }

    /** What the totals can be grouped by: the fixed groups, plus each choice question of the chosen form. */
    public function groupOptions(?Form $form): array
    {
        $options = self::GROUPS;
        foreach ($form?->fields ?? [] as $field) {
            if (in_array($field->type, ['select', 'radio', 'checkboxes', 'yesno'], true)) {
                $options['q:'.$field->label] = $field->label;
            }
        }

        return $options;
    }

    /**
     * Count per group, biggest first (dates in order).
     *
     * @return Collection<int, array{label: string, count: int}>
     */
    public function totals(Collection $submissions, string $group): Collection
    {
        $keys = function (FormSubmission $s) use ($group): array {
            $when = $s->created_at->timezone(config('app.timezone'));

            return match (true) {
                $group === 'member' => [$s->subject?->name ?? '(no Team Member)'],
                $group === 'position' => [$s->subject?->profile?->label() ?? '(no Team Member)'],
                $group === 'sender' => [$s->user?->name ?? '(unknown)'],
                $group === 'form' => [$s->form_name],
                $group === 'day' => [$when->format('Y-m-d D')],
                $group === 'week' => ['Week of '.$when->startOfWeek()->format('Y-m-d')],
                $group === 'month' => [$when->format('Y-m F')],
                str_starts_with($group, 'q:') => $this->answerKeys($s, substr($group, 2)),
                default => ['All'],
            };
        };

        $counts = [];
        foreach ($submissions as $s) {
            foreach ($keys($s) as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        $rows = collect($counts)->map(fn ($count, $label) => ['label' => (string) $label, 'count' => $count])->values();

        return in_array($group, ['day', 'week', 'month'], true)
            ? $rows->sortBy('label')->values()
            : $rows->sortBy([['count', 'desc'], ['label', 'asc']])->values();
    }

    /** A checkbox answer counts once for each box ticked. */
    private function answerKeys(FormSubmission $s, string $label): array
    {
        $a = collect($s->answers)->firstWhere('label', $label);
        if (! $a || ($a['value'] ?? null) === null || $a['value'] === '') {
            return ['(blank)'];
        }

        return ($a['type'] ?? null) === 'checkboxes' ? array_map('trim', explode(', ', $a['value'])) : [$a['value']];
    }

    /** The question labels across these submissions, in the order they were asked. */
    public function questionLabels(Collection $submissions): array
    {
        $labels = [];
        foreach ($submissions->sortBy('id') as $s) {
            foreach ($s->answers as $a) {
                if (! in_array($a['label'], $labels, true)) {
                    $labels[] = $a['label'];
                }
            }
        }

        return $labels;
    }
}
