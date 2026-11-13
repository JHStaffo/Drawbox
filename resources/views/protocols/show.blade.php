@extends('layouts.app', ['title' => $v->title])

@section('content')
<div class="stack">
    <div class="protocol-tools no-print">
        @if (auth()->user()->hasPermission('protocols'))<a href="{{ route('protocols.index') }}">← Protocol Book</a>@endif
        <span class="right">
            @if (auth()->user()->hasPermission('protocols_build'))<a class="btn btn-small btn-outline" href="{{ route('protocols.manage.edit', $v->protocol_id) }}">Builder</a>@endif
            @if (auth()->user()->hasPermission('protocols_approve') && $protocol->approved_version_id)
                <form method="post" action="{{ route('protocols.retire', $protocol) }}" onsubmit="return confirm('{{ $protocol->retired_at ? 'Put this protocol back in the book?' : 'Take this protocol out of the book? Its history is kept.' }}')">@csrf
                    <button class="btn btn-small btn-outline" type="submit">{{ $protocol->retired_at ? 'Put back in the book' : 'Retire' }}</button>
                </form>
            @endif
            <a class="btn btn-small btn-outline" href="#" onclick="window.print();return false">Print</a>
        </span>
    </div>
    @if ($v->id !== $protocol->approved_version_id || $protocol->retired_at)
        <div class="flash flash-error">
            You're looking at version {{ $v->version }} ({{ $v->statusLabel() }}).
            @if ($protocol->retired_at) This protocol is retired. @elseif ($protocol->approved_version_id) Team Members see version {{ $protocol->approved?->version }}. @else Team Members can't see it yet. @endif
        </div>
    @endif
    @include('protocols.partials.page')
</div>
@include('protocols.partials.mermaid')
@endsection
