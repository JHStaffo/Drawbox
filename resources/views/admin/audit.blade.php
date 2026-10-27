@extends('layouts.app', ['title' => 'Audit log'])

@section('band')
    <h1>Audit log</h1>
    <p>Every sign-in, failed sign-in and change made in Draw Box, newest first.</p>
@endsection

@section('content')
<div class="stack">
    <form class="card" method="get">
        <div class="field-row">
            <div class="field">
                <label for="user">Team Member</label>
                <select id="user" name="user">
                    <option value="">Anyone</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}" @selected(($filters['user'] ?? null) == $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="action">Action</label>
                <select id="action" name="action">
                    <option value="">Any action</option>
                    <option value="login.*" @selected(($filters['action'] ?? null) === 'login.*')>All sign-in activity</option>
                    @foreach ($actions as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['action'] ?? null) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="field-row">
            <div class="field"><label for="q">Search details or email</label><input id="q" type="text" name="q" value="{{ $filters['q'] ?? '' }}"></div>
            <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
            <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
        </div>
        <div class="row-actions">
            <button class="btn" type="submit">Filter</button>
            <a class="btn btn-outline" href="{{ route('admin.audit') }}">Clear</a>
        </div>
    </form>

    <div class="card">
        @if ($logs->isEmpty())
            <p class="empty">Nothing matches these filters.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>When</th><th>Who</th><th>Action</th><th>Details</th><th>IP</th></tr>
                @foreach ($logs as $log)
                    <tr>
                        <td style="white-space:nowrap">{{ $log->created_at->format('M j, Y g:i:s A') }}</td>
                        <td>{{ $log->user?->name ?? $log->email ?? 'System' }}</td>
                        <td>
                            @if (in_array($log->action, ['login.failed', 'login.unknown_email', 'login.throttled', 'login.mail_failed']))
                                <span class="pill pill-red">{{ $log->label() }}</span>
                            @elseif (str_starts_with($log->description, 'Refused'))
                                <span class="pill">{{ $log->label() }}</span>
                            @else
                                {{ $log->label() }}
                            @endif
                        </td>
                        <td>{{ $log->description }}</td>
                        <td class="hint">{{ $log->ip }}</td>
                    </tr>
                @endforeach
            </table></div>
            <div class="row-actions mt">
                @if ($logs->previousPageUrl())<a class="btn btn-outline" href="{{ $logs->previousPageUrl() }}">Newer</a>@endif
                @if ($logs->nextPageUrl())<a class="btn btn-outline" href="{{ $logs->nextPageUrl() }}">Older</a>@endif
                <span class="hint">Showing {{ $logs->firstItem() }} to {{ $logs->lastItem() }} of {{ $logs->total() }}</span>
            </div>
        @endif
    </div>
</div>
@endsection
