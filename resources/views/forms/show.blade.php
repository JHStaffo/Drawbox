@extends('layouts.app', ['title' => $form->name])

@section('band')
    <h1>{{ $form->name }}</h1>
    @if ($form->description)<p style="white-space:pre-line">{{ $form->description }}</p>@endif
@endsection

@push('scripts')
<script>
// Typing in an "Other:" box picks the Other choice next to it.
document.querySelectorAll('.other-choice input[type=text]').forEach(function (box) {
    box.addEventListener('input', function () {
        const pick = box.closest('label').querySelector('input[type=radio], input[type=checkbox]');
        if (box.value.trim() !== '') pick.checked = true;
    });
});
document.querySelectorAll('.other-box').forEach(function (box) {
    box.addEventListener('input', function () {
        const select = box.previousElementSibling;
        if (box.value.trim() !== '') select.value = '__other';
    });
});
</script>
@endpush

@section('content')
<form class="card form-fill" method="post" action="{{ route('forms.store', $form) }}" style="max-width:720px">@csrf
    @forelse ($form->fields as $field)
        @php($name = $field->inputName())
        @php($id = 'q'.$field->id)
        <div class="field">
            @if (in_array($field->type, ['radio', 'checkboxes', 'yesno'], true))
                <fieldset class="plain-fieldset">
                    <legend class="label">{{ $field->label }}@if ($field->required) <span class="req" aria-label="required">*</span>@endif</legend>
                    @php($other = \App\Models\FormField::OTHER)
                    @if ($field->type === 'checkboxes')
                        @foreach ($field->choices() as $choice)
                            <label class="check"><input type="checkbox" name="{{ $name }}[]" value="{{ $choice }}" @checked(in_array($choice, (array) old($name, []), true))> {{ $choice }}</label>
                        @endforeach
                        @if ($field->hasOther())
                            <label class="check other-choice"><input type="checkbox" name="{{ $name }}[]" value="{{ $other }}" @checked(in_array($other, (array) old($name, []), true))> Other: <input type="text" name="{{ $field->otherInputName() }}" value="{{ old($field->otherInputName()) }}" aria-label="{{ $field->label }}: other" maxlength="500"></label>
                        @endif
                    @else
                        @foreach ($field->type === 'yesno' ? ['Yes', 'No'] : $field->choices() as $choice)
                            <label class="check"><input type="radio" name="{{ $name }}" value="{{ $choice }}" @checked(old($name) === $choice) @required($field->required)> {{ $choice }}</label>
                        @endforeach
                        @if ($field->hasOther())
                            <label class="check other-choice"><input type="radio" name="{{ $name }}" value="{{ $other }}" @checked(old($name) === $other) @required($field->required)> Other: <input type="text" name="{{ $field->otherInputName() }}" value="{{ old($field->otherInputName()) }}" aria-label="{{ $field->label }}: other" maxlength="500"></label>
                        @endif
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
                            @if ($field->hasOther())<option value="{{ \App\Models\FormField::OTHER }}" @selected(old($name) === \App\Models\FormField::OTHER)>Other…</option>@endif
                        </select>
                        @if ($field->hasOther())
                            <input class="other-box mt" type="text" name="{{ $field->otherInputName() }}" value="{{ old($field->otherInputName()) }}" placeholder="If Other, type it here" aria-label="{{ $field->label }}: other" maxlength="500">
                        @endif
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
            @error($field->otherInputName())<div class="error">{{ $message }}</div>@enderror
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
