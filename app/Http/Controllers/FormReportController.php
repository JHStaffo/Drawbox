<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\FormReports;
use Illuminate\Http\Request;

/** Reports on form submissions: by date, form, team member, position and sender, with totals and a CSV. */
class FormReportController extends Controller
{
    public function index(Request $request, FormReports $reports)
    {
        $f = $reports->filters($request);
        $form = ! empty($f['form']) ? Form::with('fields.optionList.items')->find($f['form']) : null;
        $f = $reports->forForm($f, $form);
        $submissions = $reports->submissions($f, $form);
        $groups = $reports->groupOptions($form);
        // Default: a form's first question you can total by, otherwise Team Member.
        $group = isset($groups[$f['group'] ?? '']) ? $f['group'] : array_key_first($groups);
        $split = isset($groups[$f['split'] ?? '']) && $f['split'] !== $group ? $f['split'] : null;

        return view('forms.reports', [
            'f' => $f,
            'group' => $group,
            'split' => $split,
            'groups' => $groups,
            'asksTeamMember' => $reports->asksTeamMember($form),
            'filterable' => $form ? $form->fields->filter(fn ($q) => in_array($q->type, FormReports::FILTERABLE, true))->values() : collect(),
            'form' => $form,
            'forms' => Form::orderBy('name')->get(),
            'people' => User::byLastName(User::all()),
            'profiles' => PermissionProfile::ordered(),
            'periods' => FormReports::PERIODS,
            'submissions' => $submissions,
            'totals' => $reports->totals($submissions, $group),
            'matrix' => $split ? $reports->matrix($submissions, $group, $split) : null,
            'labels' => $form ? $reports->questionLabels($submissions) : [],
        ]);
    }

    public function csv(Request $request, FormReports $reports)
    {
        $f = $reports->filters($request);
        $form = ! empty($f['form']) ? Form::with('fields.optionList.items')->find($f['form']) : null;
        $f = $reports->forForm($f, $form);
        $submissions = $reports->submissions($f, $form);
        $labels = $reports->questionLabels($submissions);
        $name = 'form-report-'.$f['start']->toDateString().'-to-'.$f['end']->toDateString().'.csv';

        return response()->streamDownload(function () use ($submissions, $labels) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['#', 'Sent', 'Form', 'Team Member', 'Position', 'Sent by', 'Slack', ...$labels]);
            foreach ($submissions as $s) {
                fputcsv($out, [
                    $s->id,
                    $s->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                    $s->form_name,
                    $s->subject?->name,
                    $s->subject?->profile?->label(),
                    $s->user?->name,
                    $s->statusLabel(),
                    ...array_map(fn ($l) => $s->answer($l), $labels),
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
