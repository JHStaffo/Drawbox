@extends('layouts.app', ['title' => 'Protocol approvals'])

@section('band')
    <h1>Protocol approvals</h1>
    <p>Protocols waiting for the Medical Director. Team Members only see a protocol, or a change to one, after it's approved here.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <h2>Waiting for approval</h2>
        @if ($waiting->isEmpty())
            <p class="empty">Nothing is waiting.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>Protocol</th><th>Change</th><th>Sent</th><th></th></tr>
                @foreach ($waiting as $v)
                    <tr>
                        <td>{{ $v->fullTitle() }}<div class="hint">{{ $v->category_name ?? 'No category' }} · {{ $v->agesLabel() }}</div></td>
                        <td>{{ $v->protocol->approved ? 'Revision (version '.$v->version.' replaces '.$v->protocol->approved->version.')' : 'New protocol' }}</td>
                        <td>{{ $v->submitted_at?->format('M j, Y g:i A') }}<div class="hint">{{ $v->submitter?->name }}</div></td>
                        <td><a class="btn btn-small" href="{{ route('protocols.approvals.show', $v) }}">Review</a></td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>

    <div class="card">
        <h2>Recent decisions</h2>
        @if ($recent->isEmpty())
            <p class="empty">None yet.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>Protocol</th><th>Decision</th><th>When</th><th></th></tr>
                @foreach ($recent as $v)
                    <tr>
                        <td>{{ $v->fullTitle() }} <span class="hint">version {{ $v->version }}</span></td>
                        <td>@if ($v->status === 'returned')Sent back<div class="hint">{{ Str::limit($v->review_note, 120) }}</div>@else Approved, signed {{ $v->signature }}@endif</td>
                        <td>{{ $v->reviewed_at?->format('M j, Y g:i A') }}<div class="hint">{{ $v->reviewer?->name }}</div></td>
                        <td><a href="{{ route('protocols.version', $v) }}">View</a></td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>
</div>
@endsection
