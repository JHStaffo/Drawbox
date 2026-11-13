@extends('layouts.app', ['title' => 'Protocol Book'])

@section('band')
    <h1>{{ collect([$categoryName, $filters['age'] ? \App\Models\ProtocolVersion::AGES[$filters['age']] : null])->filter()->implode(' · ') ?: 'Protocol Book' }}</h1>
    <p>{{ $versions->count() }} {{ Str::plural('protocol', $versions->count()) }}@if ($filters['q']) matching "{{ $filters['q'] }}"@endif. <a href="{{ route('protocols.index', array_filter($filters)) }}" style="color:#fff">Back to the list</a> · <a href="#" onclick="window.print();return false" style="color:#fff">Print</a></p>
@endsection

@section('content')
@if ($versions->count() > 1)
    <nav class="card protocol-toc no-print">
        <h2>Contents</h2>
        <ol>@foreach ($versions as $v)<li><a href="#protocol-{{ $v->protocol_id }}">{{ $v->fullTitle() }}</a></li>@endforeach</ol>
    </nav>
@endif
<div class="stack">
    @forelse ($versions as $v)
        @include('protocols.partials.page', ['v' => $v])
    @empty
        <div class="card"><p class="empty">No protocols here yet.</p></div>
    @endforelse
</div>
@include('protocols.partials.mermaid')
@endsection
