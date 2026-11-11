@extends('layouts.app', ['title' => $form->name])

@section('band')
    <h1>{{ $form->name }}</h1>
    @if ($form->description)<p style="white-space:pre-line">{{ $form->description }}</p>@endif
@endsection

@section('content')
<form class="card form-fill" method="post" action="{{ route('forms.store', $form) }}" style="max-width:720px">@csrf
    @forelse ($form->fields as $field)
        @php($name = $field->inputName())
        @php($id = 'q'.$field->id)
        <div class="field">
            @if (in_array($field->type, ['radio', 'checkboxes', 'yesno'], true))
                <fieldset class="plain-fieldset">
                    <legend class="label">{{ $field->label }}@if ($field->required) <span class="req" aria-label="required">*</span>@endif</legend>
                    @if ($field->type === 'checkboxes')
                        @foreach ($field->choices() as $choice)
                            <label class="check"><input type="checkbox" name="{{ $name }}[]" value="{{ $choice }}" @checked(in_array($choice, (array) old($name, []), true))> {{ $choice }}</label>
                        @endforeach
                    @else
                        @foreach ($field->type === 'yesno' ? ['Yes', 'No'] : $field->choices() as $choice)
                            <label class="check"><input type="radio" name="{{ $name }}" value="{{ $choice }}" @checked(old($name) === $choice) @required($field->required)> {{ $choice }}</label>
                        @endforeach
                    @endif
                </fieldset>
            @else
                <label for="{{ $id }}">{{ $field->label }}@if ($field->required) <span class="req" aria-label="required">*</span>@endif</label>
                @switch($field->type)
                    @case('textarea')
                        <textarea id="{{ $id }}" name="{{ $name }}" rows="4" @required($field->required)>{{ old($name) }}</textarea>
                        @break
                    @case('select')
                        <select id="{{ $id }}" name="{{ $name }}" @required($field->required)>
                            <option value="">Choose…</option>
                            @foreach ($field->choices() as $choice)<option value="{{ $choice }}" @selected(old($name) === $choice)>{{ $choice }}</option>@endforeach
                        </select>
                        @break
                    @case('team_member')
                        <select id="{{ $id }}" name="{{ $name }}" @required($field->required)>
                            <option value="">Choose…</option>
                            @foreach ($teamMembers as $tm)<option value="{{ $tm->id }}" @selected((string) old($name) === (string) $tm->id)>{{ $tm->name }}</option>@endforeach
                        </select>
                        @break
                    @default
                        <input id="{{ $id }}" name="{{ $name }}" value="{{ old($name) }}" @required($field->required)
                            type="{{ ['number' => 'number', 'date' => 'date', 'time' => 'time', 'datetime' => 'datetime-local', 'email' => 'email', 'phone' => 'tel'][$field->type] ?? 'text' }}"
                            @if ($field->type === 'number') step="any" @endif>
                @endswitch
            @endif
            @if ($field->help)<div class="hint">{{ $field->help }}</div>@endif
            @error($name)<div class="error">{{ $message }}</div>@enderror
        </div>
    @empty
        <p class="empty">This form doesn't have any questions yet.</p>
    @endforelse
    @if ($form->fields->isNotEmpty())
        <button class="btn" type="submit">Send</button>
        @if ($form->channelLabel())<span class="hint" style="margin-left:8px">Posts to {{ $form->channelLabel() }} in Slack.</span>@endif
    @endif
</form>
<p class="mt"><a href="{{ route('forms') }}">← All forms</a></p>
@endsection
