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
            'split' => 'nullable|string|max:250',
            'q' => 'nullable|array',
            'q.*' => 'nullable|string|max:200',
        ]);
        $f['q'] = array_filter($f['q'] ?? [], fn ($v) => $v !== null && $v !== '');
        $f['period'] ??= ($request->filled('from') || $request->filled('to')) ? 'custom' : 'month';
        [$f['start'], $f['end']] = $this->range($f['period'], $f['from'] ?? null, $f['to'] ?? null);
        $f['group'] ??= null;
        $f['split'] ??= null;

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

    /** Question types a report can total by (free text works too, matched ignoring case). */
    public const GROUPABLE = ['select', 'radio', 'checkboxes', 'yesno', 'list', 'text', 'number'];

    /** Question types a report can filter by. */
    public const FILTERABLE = ['select', 'radio', 'checkboxes', 'yesno', 'list', 'text', 'number', 'email', 'phone', 'textarea'];

    /** The value a choice filter sends for "anything typed in Other". */
    public const OTHER = '__other';

    /**
     * Drops filters that don't apply to the chosen form, such as question
     * filters left over from another form, or Team Member on a form that
     * doesn't ask about one.
     */
    public function forForm(array $f, ?Form $form): array
    {
        $labels = $form ? $form->fields->whereIn('type', self::FILTERABLE)->pluck('label')->all() : [];
        $f['q'] = array_intersect_key($f['q'] ?? [], array_flip($labels));
        if (! $this->asksTeamMember($form)) {
            $f['member'] = $f['position'] = null;
        }

        return $f;
    }

    /** Submissions matching the filters, newest first. */
    public function submissions(array $f, ?Form $form = null): Collection
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
            ->get()
            ->filter(fn (FormSubmission $s) => $this->matchesQuestions($s, $f['q'] ?? [], $form))
            ->values();
    }

    /**
     * Question filters: a choice question matches its exact answer (or any
     * typed "Other" answer); anything else matches text it contains.
     */
    private function matchesQuestions(FormSubmission $s, array $q, ?Form $form): bool
    {
        foreach ($q as $label => $wanted) {
            $answer = $s->answer((string) $label);
            $field = $form?->fields->firstWhere('label', $label);
            if ($answer === null || $answer === '') {
                return false;
            }
            if ($field && in_array($field->type, ['select', 'radio', 'checkboxes', 'yesno', 'list'], true)) {
                $given = $field->type === 'checkboxes' ? array_map('trim', explode(', ', $answer)) : [$answer];
                $choices = $field->reportChoices();
                $ok = $wanted === self::OTHER
                    ? collect($given)->contains(fn ($g) => ! in_array($g, $choices, true))
                    : in_array($wanted, $given, true);
                if (! $ok) {
                    return false;
                }
            } elseif (mb_stripos($answer, $wanted) === false) {
                return false;
            }
        }

        return true;
    }

    /** What the totals can be grouped by: the fixed groups, plus each choice question of the chosen form. */
    public function groupOptions(?Form $form): array
    {
        $questions = [];
        foreach ($form?->fields ?? [] as $field) {
            if (in_array($field->type, self::GROUPABLE, true)) {
                $questions['q:'.$field->label] = $field->label;
            }
        }
        $fixed = self::GROUPS;
        if ($form) {
            unset($fixed['form']);
            if (! $this->asksTeamMember($form)) {
                unset($fixed['member'], $fixed['position']);
            }
        }

        return $questions + $fixed;
    }

    /** Whether Team Member and Position mean anything for this form (no form picked: yes). */
    public function asksTeamMember(?Form $form): bool
    {
        return ! $form || $form->fields->contains('type', 'team_member');
    }

    /**
     * A grid: one row per value of the first grouping, one column per value of
     * the second, each cell a count.
     *
     * @return array{columns: list<string>, rows: list<array{label: string, cells: array<string, int>, total: int}>, totals: array<string, int>}
     */
    public function matrix(Collection $submissions, string $group, string $split): array
    {
        $rows = [];
        $columns = [];
        foreach ($submissions as $s) {
            foreach ($this->keysFor($s, $group) as $r) {
                foreach ($this->keysFor($s, $split) as $c) {
                    $rows[$r][$c] = ($rows[$r][$c] ?? 0) + 1;
                    $columns[$c] = ($columns[$c] ?? 0) + 1;
                }
            }
        }
        $dated = fn ($g) => in_array($g, ['day', 'week', 'month'], true);
        // Columns: dates in order, otherwise most used first, then A to Z.
        $dated($split) ? ksort($columns) : uksort($columns, fn ($a, $b) => [$columns[$b], (string) $a] <=> [$columns[$a], (string) $b]);
        $out = collect($rows)->map(fn ($cells, $label) => ['label' => (string) $label, 'cells' => $cells, 'total' => array_sum($cells)])->values();
        $out = $dated($group) ? $out->sortBy('label') : $out->sortBy([['total', 'desc'], ['label', 'asc']]);

        return ['columns' => array_map('strval', array_keys($columns)), 'rows' => $out->values()->all(), 'totals' => $columns];
    }

    /**
     * Count per group, biggest first (dates in order).
     *
     * @return Collection<int, array{label: string, count: int}>
     */
    public function totals(Collection $submissions, string $group): Collection
    {
        $counts = [];
        foreach ($submissions as $s) {
            foreach ($this->keysFor($s, $group) as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        $rows = collect($counts)->map(fn ($count, $label) => ['label' => (string) $label, 'count' => $count])->values();

        return in_array($group, ['day', 'week', 'month'], true)
            ? $rows->sortBy('label')->values()
            : $rows->sortBy([['count', 'desc'], ['label', 'asc']])->values();
    }

    /** What a submission counts under for a grouping. */
    private function keysFor(FormSubmission $s, string $group): array
    {
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
    }

    /** A checkbox answer counts once for each box ticked. */
    private function answerKeys(FormSubmission $s, string $label): array
    {
        $a = collect($s->answers)->firstWhere('label', $label);
        if (! $a || ($a['value'] ?? null) === null || $a['value'] === '') {
            return ['(blank)'];
        }

        if (($a['type'] ?? null) === 'checkboxes') {
            return array_map('trim', explode(', ', $a['value']));
        }
        if (in_array($a['type'] ?? null, ['text', 'number', 'list'], true)) {
            // Typed answers group together whatever their capitals or spacing: "Sentara Leigh" = "sentara leigh ".
            $key = mb_strtolower(preg_replace('/\s+/', ' ', trim($a['value'])));

            return [$this->typed[$key] ??= trim($a['value'])];
        }

        return [$a['value']];
    }

    /** The first spelling seen of each typed answer, so groups show a real answer. */
    private array $typed = [];

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
