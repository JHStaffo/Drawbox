@extends('layouts.app', ['title' => 'Lockers'])

@section('band')
    <h1>Lockers</h1>
    <p>Each locker shows whose prizes are in it now, or who used it last, and the current or last combination.</p>
@endsection

@section('content')
<div class="stack">
    <div class="legend"><span class="l-open">Open</span><span class="l-taken">In use</span><span class="l-overdue">Overdue, a supervisor needs to check it</span></div>
    @foreach ($offices as $office)
        <div class="card">
            <h2>{{ $office['name'] }} office</h2>
            <p class="hint">{{ $office['lockers']->filter(fn ($l) => ! $l->current)->count() }} of {{ $office['lockers']->count() }} open</p>
            <div class="table-wrap">@include('partials.locker-bank', ['office' => $office])</div>
        </div>
    @endforeach
</div>
@endsection
