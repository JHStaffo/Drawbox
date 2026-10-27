@extends('layouts.app', ['title' => 'Inventory log'])

@section('band')
    <h1>Inventory change log</h1>
    <p>Every change to the box: draws, resets and manual edits.</p>
@endsection

@section('content')
<div class="card">
    <div class="table-wrap"><table>
        <tr><th>When</th><th>Item</th><th class="num">Change</th><th class="num">Now</th><th>Reason</th><th>By</th></tr>
        @forelse ($logs as $log)
            <tr>
                <td>{{ $log->created_at->format('M j, g:i A') }}</td>
                <td>{{ $log->item }}</td>
                <td class="num">{{ $log->change > 0 ? '+' : '' }}{{ $log->change }}</td>
                <td class="num">{{ $log->quantity_after }}</td>
                <td>{{ $log->reason }}</td>
                <td>{{ $log->user?->name }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">No changes yet.</td></tr>
        @endforelse
    </table></div>
    <div class="row-actions mt">
        @if ($logs->previousPageUrl())<a class="btn btn-small btn-outline" href="{{ $logs->previousPageUrl() }}">Newer</a>@endif
        @if ($logs->nextPageUrl())<a class="btn btn-small btn-outline" href="{{ $logs->nextPageUrl() }}">Older</a>@endif
    </div>
</div>
@endsection
