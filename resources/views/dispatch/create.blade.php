@extends('layouts.app', ['title' => 'Credit a call'])

@section('band')
    <h1>Credit a call</h1>
    <p>Each crew member you pick gets one draw, good for {{ $windowDays }} {{ Str::plural('day', (int) $windowDays) }}.</p>
@endsection

@push('scripts')
<script>
(function () {
    const picker = document.getElementById('crew-picker');
    const search = picker.querySelector('.picker-search');
    const list = picker.querySelector('.picker-list');
    const chips = picker.querySelector('.picker-chips');
    const empty = picker.querySelector('.picker-empty');
    const labels = Array.from(list.querySelectorAll('label'));

    // Without JavaScript the full checkbox list shows. With it, the list
    // becomes a dropdown under a search box and picked names show as chips.
    picker.classList.add('picker-ready');
    search.hidden = false;

    function open() { picker.classList.add('picker-open'); }
    function close() { picker.classList.remove('picker-open'); }

    function filter() {
        const q = search.value.trim().toLowerCase();
        let shown = 0;
        labels.forEach(function (l) {
            const match = !q || q.split(/\s+/).every(function (w) { return l.dataset.name.includes(w); });
            l.hidden = !match;
            if (match) shown++;
        });
        empty.hidden = shown > 0;
    }

    function renderChips() {
        chips.innerHTML = '';
        labels.forEach(function (l) {
            const box = l.querySelector('input');
            if (!box.checked) return;
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'chip';
            chip.textContent = l.textContent.trim() + ' \u00d7';
            chip.title = 'Remove';
            chip.addEventListener('click', function () { box.checked = false; renderChips(); });
            chips.appendChild(chip);
        });
    }

    search.addEventListener('focus', open);
    search.addEventListener('input', function () { filter(); open(); });
    search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const first = labels.find(function (l) { return !l.hidden; });
            if (first) { first.querySelector('input').checked = true; renderChips(); search.value = ''; filter(); }
        } else if (e.key === 'Escape') {
            close();
        }
    });
    list.addEventListener('change', function () { renderChips(); search.value = ''; filter(); search.focus(); });
    document.addEventListener('click', function (e) { if (!picker.contains(e.target)) close(); });

    renderChips();
})();
</script>
@endpush

@section('content')
<div class="grid grid-2">
    <form class="card" method="post" action="{{ route('dispatch.store') }}">
        @csrf
        <div class="field-row">
            <div class="field">
                <label for="occurred_at">Date and time of the call</label>
                <input id="occurred_at" type="datetime-local" name="occurred_at" value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}" required>
            </div>
            <div class="field">
                <label for="unit">Unit</label>
                <input id="unit" type="text" name="unit" value="{{ old('unit') }}" placeholder="e.g. Medic 12">
            </div>
        </div>
        <div class="field">
            <label for="location">Facility or location</label>
            <input id="location" type="text" name="location" value="{{ old('location') }}" required>
        </div>
        <div class="field">
            <label for="crew-search">Crew on the truck</label>
            <div class="picker" id="crew-picker">
                <div class="picker-chips" aria-live="polite"></div>
                <input id="crew-search" class="picker-search" type="search" placeholder="Type a name to search" autocomplete="off" hidden>
                <div class="picker-list">
                    @foreach ($employees as $e)
                        <label data-name="{{ Str::lower($e->name) }}"><input type="checkbox" name="crew[]" value="{{ $e->id }}" @checked(in_array($e->id, old('crew', [])))> {{ $e->name }}</label>
                    @endforeach
                    <div class="picker-empty empty" hidden>No one matches that name.</div>
                </div>
            </div>
        </div>
        <div class="field">
            <label for="notes">Notes (optional)</label>
            <textarea id="notes" name="notes" rows="2">{{ old('notes') }}</textarea>
        </div>
        <button class="btn btn-block" type="submit">Credit the crew</button>
    </form>

    <div class="card">
        <h2>Recently credited</h2>
        @forelse ($recent as $call)
            <div style="padding:8px 0;border-bottom:1px solid var(--line);display:flex;gap:12px;align-items:center;justify-content:space-between">
                <div>
                    <strong>{{ $call->location }}</strong>{{ $call->unit ? ' · '.$call->unit : '' }}
                    <div class="hint">{{ $call->occurred_at->format('M j, g:i A') }} · {{ $call->credits->pluck('user.name')->join(', ') }}</div>
                </div>
                @if ($call->credits->contains(fn ($c) => $c->draw))
                    <span class="hint">Already drawn</span>
                @else
                    <form method="post" action="{{ route('dispatch.destroy', $call) }}" onsubmit="return confirm('Undo this credit? The crew will lose these draws.')">
                        @csrf @method('delete')
                        <button class="btn btn-small btn-outline" type="submit">Undo</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="empty">No calls credited yet.</p>
        @endforelse
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const picker = document.getElementById('crew-picker');
    const search = picker.querySelector('.picker-search');
    const list = picker.querySelector('.picker-list');
    const chips = picker.querySelector('.picker-chips');
    const empty = picker.querySelector('.picker-empty');
    const labels = Array.from(list.querySelectorAll('label'));

    // Without JavaScript the full checkbox list shows. With it, the list
    // becomes a dropdown under a search box and picked names show as chips.
    picker.classList.add('picker-ready');
    search.hidden = false;

    function open() { picker.classList.add('picker-open'); }
    function close() { picker.classList.remove('picker-open'); }

    function filter() {
        const q = search.value.trim().toLowerCase();
        let shown = 0;
        labels.forEach(function (l) {
            const match = !q || q.split(/\s+/).every(function (w) { return l.dataset.name.includes(w); });
            l.hidden = !match;
            if (match) shown++;
        });
        empty.hidden = shown > 0;
    }

    function renderChips() {
        chips.innerHTML = '';
        labels.forEach(function (l) {
            const box = l.querySelector('input');
            if (!box.checked) return;
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'chip';
            chip.textContent = l.textContent.trim() + ' \u00d7';
            chip.title = 'Remove';
            chip.addEventListener('click', function () { box.checked = false; renderChips(); });
            chips.appendChild(chip);
        });
    }

    search.addEventListener('focus', open);
    search.addEventListener('input', function () { filter(); open(); });
    search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const first = labels.find(function (l) { return !l.hidden; });
            if (first) { first.querySelector('input').checked = true; renderChips(); search.value = ''; filter(); }
        } else if (e.key === 'Escape') {
            close();
        }
    });
    list.addEventListener('change', function () { renderChips(); search.value = ''; filter(); search.focus(); });
    document.addEventListener('click', function (e) { if (!picker.contains(e.target)) close(); });

    renderChips();
})();
</script>
@endpush
