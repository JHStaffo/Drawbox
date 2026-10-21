{{-- One office's lockers drawn as a bank of doors. $pick turns each open door into a choice. --}}
<div @class(["locker-bank", "locker-bank-map" => ! ($pick ?? false)]) style="--cols: {{ $office['cols'] }}">
    @foreach ($office['lockers'] as $locker)
        @php($current = $locker->current)
        @php($state = ! $current ? 'open' : ($current->isForfeited() ? 'forfeited' : 'taken'))
        @if ($pick ?? false)
            <label class="locker locker-{{ $state }}" style="grid-row: {{ $locker->row + 1 }}; grid-column: {{ $locker->col + 1 }}" title="{{ $current ? 'In use' : 'Locker '.$locker->number }}">
                <input type="radio" name="locker_id" value="{{ $locker->id }}" @disabled($current) @checked(old('locker_id') == $locker->id)>
                <span class="locker-num">{{ $locker->number }}</span>
                @if ($current)<span class="locker-who">In use</span>@endif
            </label>
        @else
            @php($shown = $current ?? $locker->latest)
            <div class="locker locker-{{ $state }}" style="grid-row: {{ $locker->row + 1 }}; grid-column: {{ $locker->col + 1 }}">
                <span class="locker-num">{{ $locker->number }}</span>
                <span class="locker-state">{{ ['open' => 'Open', 'taken' => 'In use', 'forfeited' => 'Forfeited'][$state] }}</span>
                @if ($shown)
                    <span class="locker-who">{{ $current ? '' : 'Last: ' }}{{ $shown->user->name }}</span>
                    <span class="locker-combo">{{ $shown->combination }}</span>
                    @if ($current && ! $current->isForfeited())<span class="locker-who">Due {{ $current->due_at->format('M j') }}</span>@endif
                @endif
            </div>
        @endif
    @endforeach
</div>
