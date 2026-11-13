@extends('layouts.app', ['title' => 'Protocol Book'])

@section('band')
    <h1>Protocol Book</h1>
    <p>Fast Track EMS protocols approved by the Medical Director. Search, or open the whole book, a category or an age group.</p>
@endsection

@section('content')
<div class="stack">
    @include('protocols.partials.filters')

    <div class="card">
        <div class="protocol-open-row">
            <span class="stat-label">Open as a book</span>
            <a class="btn btn-small btn-outline" href="{{ route('protocols.book') }}">Whole book</a>
            @foreach ($categories as $c)<a class="btn btn-small btn-outline" href="{{ route('protocols.book', ['category' => $c->id]) }}">{{ $c->name }}</a>@endforeach
            @foreach (\App\Models\ProtocolVersion::AGES as $key => $label)<a class="btn btn-small btn-teal" href="{{ route('protocols.book', ['age' => $key]) }}">{{ $label }}</a>@endforeach
        </div>
    </div>

    <div class="card">
        @php($filtered = $filters['q'] || $filters['category'] || $filters['age'])
        <div class="protocol-list-head">
            <h2 style="margin:0">{{ $filtered ? $versions->count().' '.Str::plural('protocol', $versions->count()).' found' : 'All protocols' }}</h2>
            @if ($filtered && $versions->isNotEmpty())
                <a class="btn btn-small" href="{{ route('protocols.book', array_filter($filters)) }}">Open these as a book</a>
            @endif
        </div>
        @forelse ($groups as $category => $list)
            <h3 class="mt">{{ $category }}</h3>
            <ul class="protocol-list">
                @foreach ($list as $v)
                    <li>
                        <a href="{{ route('protocols.show', $v->protocol_id) }}">@if ($v->number)<span class="protocol-num">{{ $v->number }}</span>@endif{{ $v->title }}</a>
                        <span class="hint">{{ $v->agesLabel() }}</span>
                        @if ($snippets[$v->id] ?? null)<div class="hint protocol-snippet">{{ $snippets[$v->id] }}</div>@endif
                    </li>
                @endforeach
            </ul>
        @empty
            <p class="empty">{{ $filtered ? 'Nothing matches. Try fewer words or another category.' : 'No protocols have been approved yet.' }}</p>
        @endforelse
    </div>
</div>
@endsection
