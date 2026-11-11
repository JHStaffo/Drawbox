@extends('layouts.app', ['title' => $form->name])

@section('band')
    <h1>{{ $form->name }}</h1>
    <p><a href="{{ route('admin.forms') }}" style="color:#fff">← All forms</a> · <a href="{{ route('admin.forms.submissions', ['form' => $form->id]) }}" style="color:#fff">Submissions</a>@if ($form->active) · <a href="{{ route('forms.show', $form) }}" style="color:#fff">Open the form</a>@endif</p>
@endsection

@section('content')
@php($choiceTypes = \App\Models\FormField::WITH_OPTIONS)
<div class="stack">
    <form class="card" method="post" action="{{ route('admin.forms.update', $form) }}" style="max-width:720px">@csrf @method('put')
        <h2>Form settings</h2>
        <div class="field"><label for="name">Name</label><input id="name" type="text" name="name" value="{{ old('name', $form->name) }}" required maxlength="150"></div>
        <div class="field">
            <label for="description">Instructions (optional)</label>
            <textarea id="description" name="description" rows="2">{{ old('description', $form->description) }}</textarea>
            <div class="hint">Shown at the top of the form.</div>
        </div>
        <div class="field">
            <label for="slack_channel">Slack channel</label>
            @if ($channels)
                <select id="slack_channel" name="slack_channel">
                    <option value="">Don't post (save in Pulse only)</option>
                    @foreach ($channels as $id => $name)<option value="{{ $id }}" @selected(old('slack_channel', $form->slack_channel) === $id)>#{{ $name }}</option>@endforeach
                    @if ($form->slack_channel && ! isset($channels[$form->slack_channel]))<option value="{{ $form->slack_channel }}" selected>{{ $form->channelLabel() }} (not in the list)</option>@endif
                </select>
                <div class="hint">Don't see a private channel? Invite the app to it in Slack (<code>/invite @Fast Track Pulse</code>), then <a href="{{ route('admin.forms.edit', [$form, 'refresh' => 1]) }}">refresh the list</a>.</div>
            @else
                <input id="slack_channel" type="text" name="slack_channel" value="{{ old('slack_channel', $form->slack_channel) }}" placeholder="C0123ABCD">
                <div class="hint">@if ($connected)Pulse couldn't list channels, so type the channel ID (at the bottom of the channel's details in Slack).@else Slack isn't connected yet. <a href="{{ route('admin.slack') }}">Connect it</a> to pick from a list.@endif Leave blank to save submissions in Pulse only.</div>
            @endif
        </div>
        <div class="field">
            <input type="hidden" name="active" value="0">
            <label class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $form->active))> On (team members with Forms access can fill it out)</label>
        </div>
        <button class="btn" type="submit">Save form</button>
    </form>

    <div class="card">
        <h2>Questions ({{ $form->fields->count() }})</h2>
        <p class="hint">Choices go one per line, for dropdown, multiple choice and checkbox questions. Changing or removing a question doesn't change past submissions.</p>
        @if ($form->fields->isNotEmpty())
            <div class="table-wrap"><table class="field-table">
                <tr><th></th><th>Question</th><th>Type</th><th>Choices</th><th>Help text</th><th>Required</th><th></th></tr>
                @foreach ($form->fields as $field)
                    <tr>
                        <td style="white-space:nowrap">
                            <form method="post" action="{{ route('admin.forms.fields.move', $field) }}" style="display:inline">@csrf<input type="hidden" name="direction" value="up"><button class="btn btn-small btn-outline" type="submit" aria-label="Move up" @disabled($loop->first)>↑</button></form>
                            <form method="post" action="{{ route('admin.forms.fields.move', $field) }}" style="display:inline">@csrf<input type="hidden" name="direction" value="down"><button class="btn btn-small btn-outline" type="submit" aria-label="Move down" @disabled($loop->last)>↓</button></form>
                        </td>
                        <td><input form="ff{{ $field->id }}" type="text" name="label" value="{{ $field->label }}" required aria-label="Question"></td>
                        <td><select form="ff{{ $field->id }}" name="type" aria-label="Type">@foreach ($types as $k => $l)<option value="{{ $k }}" @selected($field->type === $k)>{{ $l }}</option>@endforeach</select></td>
                        <td><textarea form="ff{{ $field->id }}" name="options" rows="{{ max(1, min(5, count($field->choices()))) }}" aria-label="Choices" placeholder="{{ in_array($field->type, $choiceTypes, true) ? 'One per line' : 'Not needed' }}">{{ $field->options }}</textarea></td>
                        <td><input form="ff{{ $field->id }}" type="text" name="help" value="{{ $field->help }}" aria-label="Help text"></td>
                        <td><input form="ff{{ $field->id }}" type="hidden" name="required" value="0"><input form="ff{{ $field->id }}" type="checkbox" name="required" value="1" @checked($field->required) aria-label="Required"></td>
                        <td class="right" style="white-space:nowrap">
                            <form id="ff{{ $field->id }}" method="post" action="{{ route('admin.forms.fields.update', $field) }}" style="display:inline">@csrf @method('put')<button class="btn btn-small" type="submit">Save</button></form>
                            <form method="post" action="{{ route('admin.forms.fields.destroy', $field) }}" style="display:inline" onsubmit="return confirm('Remove this question?')">@csrf @method('delete')<button class="btn btn-small btn-outline" type="submit">Remove</button></form>
                        </td>
                    </tr>
                @endforeach
            </table></div>
        @endif

        <h3 class="mt">Add a question</h3>
        <form method="post" action="{{ route('admin.forms.fields.store', $form) }}">@csrf
            <div class="field-row">
                <div class="field"><label for="new-label">Question</label><input id="new-label" type="text" name="label" required maxlength="200"></div>
                <div class="field"><label for="new-type">Type</label><select id="new-type" name="type">@foreach ($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div class="field"><label for="new-help">Help text (optional)</label><input id="new-help" type="text" name="help" maxlength="255"></div>
            </div>
            <div class="field"><label for="new-options">Choices (one per line, for dropdown, multiple choice and checkboxes)</label><textarea id="new-options" name="options" rows="3"></textarea></div>
            <div class="field"><input type="hidden" name="required" value="0"><label class="check"><input type="checkbox" name="required" value="1"> Required</label></div>
            <button class="btn" type="submit">Add question</button>
        </form>
    </div>

    <div class="card" style="max-width:720px">
        <h2>Test and delete</h2>
        <div class="row-actions">
            <form method="post" action="{{ route('admin.forms.test', $form) }}">@csrf<button class="btn btn-teal" type="submit" @disabled(! $form->slack_channel)>Send a test to {{ $form->channelLabel() ?? 'Slack' }}</button></form>
            <form method="post" action="{{ route('admin.forms.destroy', $form) }}" onsubmit="return confirm('Delete {{ addslashes($form->name) }}?')">@csrf @method('delete')<button class="btn btn-outline" type="submit">Delete form</button></form>
        </div>
        <p class="hint mt">The test posts a sample with every question, marked TEST, so you can see how it looks in Slack. A form with submissions can't be deleted; turn it off instead.</p>
    </div>
</div>
@endsection
