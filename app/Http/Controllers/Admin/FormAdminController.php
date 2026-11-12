<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Services\Forms;
use App\Services\Slack;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Building forms, choosing their Slack channel, and looking at what was sent. */
class FormAdminController extends Controller
{
    public function index(Slack $slack)
    {
        return view('admin.forms.index', [
            'forms' => Form::withCount(['fields', 'submissions'])->orderBy('name')->get(),
            'connected' => $slack->connected(),
            'failed' => FormSubmission::where('slack_status', 'failed')->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:150']);
        $form = Form::create(['name' => trim($data['name']), 'active' => false]);

        return redirect()->route('admin.forms.edit', $form)
            ->with('status', "Created {$form->name}. Add its questions and pick a Slack channel, then turn it on.");
    }

    public function edit(Request $request, Form $form, Slack $slack)
    {
        return view('admin.forms.edit', [
            'form' => $form->load('fields.optionList'),
            'types' => FormField::TYPES,
            'lists' => \App\Models\OptionList::orderBy('name')->get(),
            'channels' => $slack->channels($request->boolean('refresh')),
            'connected' => $slack->connected(),
        ]);
    }

    public function update(Request $request, Form $form, Slack $slack)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'slack_template' => 'nullable|string|max:10000',
            'slack_channel' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9]+$/'],
            'active' => 'boolean',
        ], ['slack_channel.regex' => 'A Slack channel ID is letters and numbers, like C0123ABCD. It\'s at the bottom of the channel\'s details in Slack.']);

        $channel = $data['slack_channel'] ?? null;
        $form->update([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'slack_template' => trim((string) ($data['slack_template'] ?? '')) ?: null,
            'slack_channel' => $channel,
            'slack_channel_name' => $channel ? ($slack->channels()[$channel] ?? ($channel === $form->slack_channel ? $form->slack_channel_name : null)) : null,
            'active' => $request->boolean('active'),
        ]);

        return back()->with('status', "Saved {$form->name}.".($form->active ? '' : ' It\'s off, so nobody can fill it out yet.'));
    }

    public function destroy(Form $form)
    {
        if ($form->submissions()->exists()) {
            return back()->with('error', "{$form->name} has submissions, so it can't be deleted. Turn it off instead to hide it.");
        }
        $form->delete();

        return redirect()->route('admin.forms')->with('status', "Deleted {$form->name}.");
    }

    public function test(Request $request, Form $form, Forms $forms)
    {
        try {
            $forms->sendTest($form->load('fields'), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', 'The test didn\'t go through. '.$e->getMessage());
        }

        return back()->with('status', "Test message sent to {$form->channelLabel()}. Check Slack.");
    }

    public function storeField(Request $request, Form $form)
    {
        $field = $form->fields()->create($this->fieldData($request) + ['position' => (int) $form->fields()->max('position') + 1]);

        return back()->with('status', "Added \"{$field->label}\".");
    }

    public function updateField(Request $request, FormField $field)
    {
        $field->update($this->fieldData($request));

        return back()->with('status', "Saved \"{$field->label}\".");
    }

    public function destroyField(FormField $field)
    {
        $form = $field->form;
        $ids = $form->fields()->pluck('id')->values();
        $removed = $ids->search($field->id);
        $field->delete();
        // $answer numbers after the removed question move up one; the removed one is dropped from the message.
        $form->renumberTemplate($ids->keys()->mapWithKeys(fn ($i) => [$i + 1 => $i === $removed ? null : ($i > $removed ? $i : $i + 1)])->all());

        return back()->with('status', "Removed \"{$field->label}\". Past submissions keep their answers to it.");
    }

    /** Swaps a question with the one above or below it. */
    public function moveField(Request $request, FormField $field)
    {
        $direction = $request->validate(['direction' => 'required|in:up,down'])['direction'];
        $fields = $field->form->fields()->get()->values();
        $i = $fields->search(fn ($f) => $f->id === $field->id);
        $j = $direction === 'up' ? $i - 1 : $i + 1;
        if (isset($fields[$j])) {
            // Renumber everything so ties from older rows can't stall a move.
            $order = $fields->all();
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            foreach ($order as $pos => $f) {
                $f->updateQuietly(['position' => $pos + 1]);
            }
            $field->form->renumberTemplate([$i + 1 => $j + 1, $j + 1 => $i + 1]);
        }
        audit_note("Moved \"{$field->label}\" {$direction} on the {$field->form->name} form.");

        return back();
    }

    public function submissions(Request $request)
    {
        $data = $request->validate(['form' => 'nullable|integer', 'status' => 'nullable|in:sent,failed,off,pending']);

        return view('admin.forms.submissions', [
            'forms' => Form::orderBy('name')->get(),
            'filters' => $data,
            'submissions' => FormSubmission::with('user')
                ->when($data['form'] ?? null, fn ($q, $id) => $q->where('form_id', $id))
                ->when($data['status'] ?? null, fn ($q, $s) => $q->where('slack_status', $s))
                ->latest('id')->paginate(50)->withQueryString(),
        ]);
    }

    public function resend(FormSubmission $submission, Forms $forms)
    {
        $label = "{$submission->form_name} #{$submission->id}";
        if ($submission->slack_status === 'sent') {
            return back()->with('error', "{$label} was already posted to Slack.");
        }
        // Resend uses the form's current channel, in case the old one was the problem.
        $submission->update(['slack_channel' => $submission->form?->slack_channel ?? $submission->slack_channel]);

        return $forms->post($submission)
            ? back()->with('status', "Posted {$label} to Slack.")
            : back()->with('error', "{$label} still didn't post. ".$submission->slack_error);
    }

    private function fieldData(Request $request): array
    {
        $data = $request->validate([
            'label' => 'required|string|max:200',
            'type' => ['required', Rule::in(array_keys(FormField::TYPES))],
            'options' => 'nullable|string|max:5000',
            'help' => 'nullable|string|max:255',
            'required' => 'boolean',
            'allow_other' => 'boolean',
            'option_list_id' => 'nullable|integer|exists:option_lists,id',
        ]);
        if ($data['type'] === 'list' && empty($data['option_list_id'])) {
            // Switched to a shared list without picking one: start with the first list; it can be changed after.
            $data['option_list_id'] = \App\Models\OptionList::orderBy('id')->value('id');
        }
        if ($data['type'] === 'list' && empty($data['option_list_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['option_list_id' => 'Pick which shared list this question uses.']);
        }
        $needsChoices = in_array($data['type'], FormField::WITH_OPTIONS, true);
        if ($needsChoices && trim((string) ($data['options'] ?? '')) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['options' => 'Add the choices, one per line.']);
        }

        return [
            'label' => trim($data['label']),
            'type' => $data['type'],
            'options' => $needsChoices ? trim($data['options']) : null,
            'help' => $data['help'] ?? null,
            'required' => $request->boolean('required'),
            'allow_other' => $needsChoices && $request->boolean('allow_other'),
            'option_list_id' => $data['type'] === 'list' ? $data['option_list_id'] : null,
        ];
    }
}
