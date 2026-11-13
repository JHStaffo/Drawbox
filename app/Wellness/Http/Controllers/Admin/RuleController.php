<?php

namespace App\Wellness\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Wellness\Calendar;
use App\Wellness\Models\Rule;
use Illuminate\Http\Request;

/**
 * Point rules. A change never rewrites a rule: it adds a new version that
 * starts on a chosen date (today or later), so earlier days keep their scores.
 */
class RuleController extends Controller
{
    public function index()
    {
        $all = Rule::orderBy('effective_from')->get();

        return view('wellness.admin.rules', [
            'rules' => Rule::forDay(Calendar::today()->toDateString(), $all),
            'upcoming' => $all->filter(fn (Rule $r) => $r->effective_from->isFuture())->sortBy('effective_from'),
            'history' => $all->groupBy('key'),
        ]);
    }

    public function store(Request $request, string $key)
    {
        $current = Rule::forDay(Calendar::today()->toDateString())->get($key);
        abort_unless($current, 404);
        $data = $request->validate([
            'effective_from' => 'required|date|after_or_equal:today',
            'name' => 'required|string|max:100',
            'points' => 'required|integer|min:0|max:1000',
            'threshold' => $current->isDaily() ? 'required|numeric|min:0|max:150000' : 'nullable',
            'max_per_day' => $current->isDaily() ? 'required|integer|min:0|max:24' : 'nullable',
            'cap_points' => $current->isDaily() ? 'nullable' : 'nullable|integer|min:0|max:10000',
            'cap_period' => $current->isDaily() ? 'nullable' : 'nullable|in:week,month,year',
            'description' => 'nullable|string|max:255',
        ]);

        $from = Calendar::date($data['effective_from'])->toDateString();
        $fields = [
            'name' => $data['name'], 'category' => $current->category, 'metric' => $current->metric,
            'threshold' => $current->isDaily() ? $data['threshold'] : null, 'unit' => $current->unit,
            'points' => $data['points'], 'per_unit' => $current->per_unit,
            'max_per_day' => $current->isDaily() ? $data['max_per_day'] : null,
            'cap_points' => $current->isDaily() ? null : ($data['cap_points'] ?? null),
            'cap_period' => $current->isDaily() ? null : (($data['cap_points'] ?? null) ? ($data['cap_period'] ?? 'year') : null),
            'needs_note' => $current->needs_note, 'description' => $data['description'] ?? null,
            'position' => $current->position, 'created_by' => $request->user()->id,
        ];

        $existing = Rule::where('key', $key)->whereDate('effective_from', $from)->first();
        $before = $existing ?? $current;
        $changes = collect(['name' => 'name', 'points' => 'points', 'threshold' => 'goal', 'max_per_day' => 'most a day', 'cap_points' => 'cap', 'cap_period' => 'cap period', 'description' => 'description'])
            ->filter(fn ($label, $field) => (string) ($before->{$field} ?? '') !== (string) ($fields[$field] ?? ''))
            ->map(fn ($label, $field) => "{$label} from ".(($before->{$field} ?? '') === '' ? 'none' : $before->{$field}).' to '.(($fields[$field] ?? '') === '' ? 'none' : $fields[$field]));

        if ($changes->isEmpty()) {
            return back()->with('status', 'Nothing changed.');
        }
        if ($existing) {
            $existing->update($fields);
        } else {
            Rule::create(['key' => $key, 'effective_from' => $from] + $fields);
        }
        $date = Calendar::date($from)->format('M j, Y');
        audit_note("Changed the wellness rule \"{$current->name}\" starting {$date}: ".$changes->implode(', ').'.');

        return back()->with('status', "Saved. The new \"{$data['name']}\" rule applies from {$date}. Earlier days keep the rule they were scored under.");
    }
}
