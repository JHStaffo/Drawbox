@extends('layouts.app', ['title' => 'EMS check '.$check->created_at->format('m/d/Y')])

@section('band')
    <h1>EMS affiliation check, {{ $check->created_at->format('m/d/Y g:i A') }}</h1>
    <p>{{ $check->members_total }} active Team Members · {{ $check->found_count }} found · run by {{ $check->runner?->name ?? 'the monthly automatic check' }}</p>
@endsection

@section('content')
<div class="stack">
    <p><a href="{{ route('admin.ems') }}">← All checks</a></p>

    <div class="card">
        <div class="table-wrap"><table>
            <tr><th>Team Member</th><th>Result</th><th>Other agencies</th></tr>
            @foreach ($results as $result)
                @php([$label, $class] = $result->pill())
                @php($userLinks = $links[$result->user_id] ?? collect())
                <tr>
                    <td>
                        @if ($result->user)<a href="{{ route('admin.ems.member', $result->user) }}">{{ $result->name }}</a>@else{{ $result->name }}@endif
                        @if ($result->cert)<div class="hint">#{{ $result->cert }}{{ $result->level ? ' · '.$result->level : '' }}{{ $result->expires ? ' · expires '.$result->expires : '' }}</div>@endif
                    </td>
                    <td>
                        <span class="pill {{ $class }}">{{ $label }}</span>
                        @if ($result->exclusion_found)<div class="hint" style="margin-top:4px"><strong style="color:var(--red)">Marked accepted exclusion, but found on the search.</strong></div>@endif
                        @if ($result->status === 'found' && ! $result->has_ours)<div class="hint" style="margin-top:4px">{{ $ours }} isn't one of their agencies.</div>@endif
                        @if ($result->status === 'failed')<div class="hint" style="margin-top:4px">{{ $result->error }}</div>@endif
                        @if ($result->status === 'multiple')
                            <div class="hint" style="margin-top:4px">Set their certification # so the right one is searched:</div>
                            <ul class="hint" style="margin:4px 0;padding-left:20px">
                                @foreach ($result->providers ?? [] as $p)
                                    <li>{{ $p['name'] }} · #{{ $p['cert'] ?? '?' }}{{ $p['level'] ? ' · '.$p['level'] : '' }} · {{ implode('; ', $p['agencies'] ?? []) ?: 'no agencies listed' }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($result->status === 'not_found' && ! $result->accepted_exclusion)<div class="hint" style="margin-top:4px">Check the name or set their certification #, or mark them accepted exclusion.</div>@endif
                    </td>
                    <td>
                        @foreach ($result->agencies ?? [] as $agency)
                            @php($key = \App\Services\Ems\Agency::key($agency))
                            @continue($key === \App\Services\Ems\Agency::key($ours))
                            @php($link = $userLinks->firstWhere('agency_key', $key))
                            <div>{{ $agency }}
                                @if ($link)@php([$l, $c] = $link->pill())<span class="pill {{ $c }}">{{ $l }}</span>@endif
                                @if (in_array($agency, $result->new_agencies ?? [], true))<span class="hint">new on this check</span>@endif
                            </div>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </table></div>
    </div>
</div>
@endsection
