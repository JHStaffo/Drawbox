<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\User;
use App\Services\Forms;
use Illuminate\Http\Request;

/** Filling out forms. Each one is saved and posted to its Slack channel. */
class FormController extends Controller
{
    public function index(Request $request)
    {
        return view('forms.index', [
            'forms' => Form::where('active', true)->orderBy('name')->get(),
            'mine' => $request->user()->hasPermission('forms')
                ? \App\Models\FormSubmission::where('user_id', $request->user()->id)->latest('id')->limit(15)->get()
                : collect(),
        ]);
    }

    public function show(Form $form)
    {
        abort_unless($form->active, 404);

        return view('forms.show', [
            'form' => $form->load('fields.optionList.items'),
            'teamMembers' => $form->fields->contains('type', 'team_member') ? User::byLastName(User::where('active', true)->get()) : collect(),
        ]);
    }

    public function store(Request $request, Form $form, Forms $forms)
    {
        abort_unless($form->active, 404);
        $form->load('fields.optionList.items');
        $input = $request->validate($forms->rules($form), [], $forms->attributes($form));

        $submission = $forms->submit($form, $input, $request->user());
        audit_note("Sent the {$form->name} form (#{$submission->id})".match ($submission->slack_status) {
            'sent' => ' and posted it to Slack.',
            'failed' => '. Slack didn\'t take it: '.$submission->slack_error,
            default => '.',
        }.($forms->added ? ' Added '.implode(', ', $forms->added).'.' : ''));

        $message = match ($submission->slack_status) {
            'sent' => "Sent. {$form->name} was posted to {$form->channelLabel()}.",
            'failed' => "Saved, but it didn't post to Slack yet. Pulse will keep trying for a while, and an admin can see it under Admin > Forms. ({$submission->slack_error})",
            default => "Saved {$form->name}.",
        };

        return redirect()->route('forms.show', $form)->with($submission->slack_status === 'failed' ? 'error' : 'status', $message);
    }
}
