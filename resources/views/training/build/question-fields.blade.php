{{-- The fields of one quiz question. $q is the question being edited, or null for a new one. $key keeps ids apart. --}}
@php($type = old('type', $q?->type ?? 'single'))
@php($choices = $q && $q->type !== 'true_false' ? $q->choices : [])
<div class="field-row">
    <div class="field"><label for="type-{{ $key }}">Kind of question</label>
        <select id="type-{{ $key }}" name="type" class="q-type">@foreach (\App\Training\Models\Question::TYPES as $k => $label)<option value="{{ $k }}" @selected($type === $k)>{{ $label }}</option>@endforeach</select>
    </div>
</div>
<div class="field"><label for="prompt-{{ $key }}">Question</label><textarea id="prompt-{{ $key }}" name="prompt" rows="2" required maxlength="5000">{{ $q?->prompt }}</textarea></div>
<div class="field q-choices" @if ($type === 'true_false') hidden @endif>
    <label>Answers <span class="hint">(tick the right one{{ $type === 'multiple' ? 's' : '' }})</span></label>
    @for ($i = 0; $i < max(4, count($choices) + 1); $i++)
        <div class="choice-edit">
            <input type="checkbox" name="correct[]" value="{{ $i }}" aria-label="Answer {{ $i + 1 }} is right" @checked(! empty($choices[$i]['correct']))>
            <input type="text" name="choices[{{ $i }}]" value="{{ $choices[$i]['text'] ?? '' }}" maxlength="1000" placeholder="Answer {{ $i + 1 }}">
        </div>
    @endfor
    <div class="hint">Leave extra boxes empty. Up to 10 answers; save to get more boxes.</div>
</div>
<div class="field q-tf" @if ($type !== 'true_false') hidden @endif>
    <label>The right answer is</label>
    @php($tf = $q && $q->type === 'true_false' ? ($q->choices[0]['correct'] ? 'true' : 'false') : null)
    <label class="check"><input type="radio" name="true_false" value="true" @checked($tf === 'true')> True</label>
    <label class="check"><input type="radio" name="true_false" value="false" @checked($tf === 'false')> False</label>
</div>
<div class="field"><label for="exp-{{ $key }}">Explanation <span class="hint">(optional, shown with the results)</span></label><textarea id="exp-{{ $key }}" name="explanation" rows="2" maxlength="5000">{{ $q?->explanation }}</textarea></div>
