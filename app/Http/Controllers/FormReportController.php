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
        $form = ! empty($f['form']) ? Form::with('fields')->find($f['form']) : null;
        $submissions = $reports->submissions($f);
        $groups = $reports->groupOptions($form);
        $group = isset($groups[$f['group']]) ? $f['group'] : 'member';

        return view('forms.reports', [
            'f' => $f,
            'group' => $group,
            'groups' => $groups,
            'form' => $form,
            'forms' => Form::orderBy('name')->get(),
            'people' => User::byLastName(User::all()),
            'profiles' => PermissionProfile::ordered(),
            'periods' => FormReports::PERIODS,
            'submissions' => $submissions,
            'totals' => $reports->totals($submissions, $group),
            'labels' => $form ? $reports->questionLabels($submissions) : [],
        ]);
    }

    public function csv(Request $request, FormReports $reports)
    {
        $f = $reports->filters($request);
        $submissions = $reports->submissions($f);
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
