@extends('layouts.app', ['title' => 'OIG Exclusions'])

@section('band')
    <h1>OIG Exclusions</h1>
    <p>Every active Team Member checked against the OIG's List of Excluded Individuals/Entities. It runs on its own on the 15th of each month, and every check is kept.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <form method="post" action="{{ route('admin.oig.store') }}" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Checking…';">@csrf
            <button class="btn" type="submit">Check now</button>
        </form>
        <p class="hint mt">Downloads the OIG's current list and compares names and dates of birth. A name match is confirmed by hand on the OIG website with the SSN, and recorded on that check.</p>
    </div>

    <div class="card">
        <h2>Checks</h2>
        @if ($checks->isEmpty())
            <p class="hint">No checks yet.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>Date checked</th><th>Result</th><th>Team Members</th><th>OIG list dated</th><th>Run by</th></tr>
                @foreach ($checks as $check)
                    <tr>
                        <td>
                            @if ($check->status === 'done')
                                <a href="{{ route('admin.oig.show', $check) }}">{{ $check->created_at->format('m/d/Y g:i A') }}</a>
                            @else
                                {{ $check->created_at->format('m/d/Y g:i A') }}
                            @endif
                        </td>
                        <td>
                            @if ($check->status === 'failed')
                                <span class="pill pill-amber">Didn't run</span> <span class="hint">{{ $check->error }}</span>
                            @else
                                @if ($check->excluded_count)
                                    <strong style="color:var(--red)">{{ $check->excluded_count }} {{ Str::plural('exclusion', $check->excluded_count) }} found</strong>
                                @else
                                    0 exclusions found
                                @endif
                                @if ($check->review_count)
                                    <div><span class="pill pill-amber">{{ $check->review_count }} to check by hand</span></div>
                                @endif
                            @endif
                        </td>
                        <td>{{ $check->status === 'done' ? $check->members_checked : '' }}</td>
                        <td>{{ $check->list_updated_at?->format('m/d/Y') }}</td>
                        <td>{{ $check->runner?->name ?? 'Monthly (automatic)' }}</td>
                    </tr>
                @endforeach
            </table></div>
            {{ $checks->links() }}
        @endif
    </div>
</div>
@endsection
