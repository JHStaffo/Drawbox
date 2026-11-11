@extends('layouts.app', ['title' => 'Form submissions'])

@section('band')
    <h1>Form submissions</h1>
    <p><a href="{{ route('admin.forms') }}" style="color:#fff">← All forms</a></p>
@endsection

@section('content')
<div class="stack">
    <form class="card" method="get" action="{{ route('admin.forms.submissions') }}">
        <div class="field-row">
            <div class="field"><label for="f-form">Form</label><select id="f-form" name="form"><option value="">All forms</option>@foreach ($forms as $f)<option value="{{ $f->id }}" @selected(($filters['form'] ?? null) == $f->id)>{{ $f->name }}</option>@endforeach</select></div>
            <div class="field"><label for="f-status">Slack</label><select id="f-status" name="status"><option value="">Any</option>@foreach (['sent' => 'Posted', 'failed' => 'Not posted yet', 'off' => 'Saved only'] as $k => $l)<option value="{{ $k }}" @selected(($filters['status'] ?? null) === $k)>{{ $l }}</option>@endforeach</select></div>
        </div>
        <button class="btn btn-small" type="submit">Show</button>
    </form>

    <div class="card">
        @if ($submissions->isEmpty())
            <p class="empty">Nothing here yet.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>#</th><th>When</th><th>Form</th><th>Sent by</th><th>Slack</th><th></th></tr>
                @foreach ($submissions as $s)
                    <tr>
                        <td>{{ $s->id }}</td>
                        <td style="white-space:nowrap">{{ $s->created_at->format('M j, Y g:i A') }}</td>
                        <td>
                            <details>
                                <summary>{{ $s->form_name }}</summary>
                                <dl class="answers mt">
                                    @foreach ($s->answers as $a)<dt>{{ $a['label'] }}</dt><dd>{{ $a['value'] ?? '—' }}</dd>@endforeach
                                </dl>
                            </details>
                        </td>
                        <td>{{ $s->user?->name ?? '—' }}</td>
                        <td>
                            <span class="pill {{ $s->statusClass() }}">{{ $s->statusLabel() }}</span>
                            @if ($s->slack_status === 'failed' && $s->slack_error)<div class="hint">{{ $s->slack_error }} (tried {{ $s->slack_attempts }}×)</div>@endif
                        </td>
                        <td class="right">
                            @if ($s->slack_status !== 'sent' && $s->form?->slack_channel)
                                <form method="post" action="{{ route('admin.forms.resend', $s) }}">@csrf<button class="btn btn-small" type="submit">Post now</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table></div>
            <div class="row-actions mt">
                @if ($submissions->previousPageUrl())<a class="btn btn-outline" href="{{ $submissions->previousPageUrl() }}">Newer</a>@endif
                @if ($submissions->nextPageUrl())<a class="btn btn-outline" href="{{ $submissions->nextPageUrl() }}">Older</a>@endif
                <span class="hint">Showing {{ $submissions->firstItem() }} to {{ $submissions->lastItem() }} of {{ $submissions->total() }}</span>
            </div>
        @endif
    </div>
</div>
@endsection
