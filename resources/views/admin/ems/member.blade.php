@extends('layouts.app', ['title' => 'EMS affiliations: '.$member->name])

@section('band')
    <h1>{{ $member->name }}</h1>
    <p>Virginia EMS provider search: certification #, agencies they've been listed with, and every check.</p>
@endsection

@section('content')
<div class="stack">
    <p><a href="{{ route('admin.ems') }}">← EMS Affiliations</a></p>

    <div class="grid grid-2">
        <div class="card">
            <h2>Certification #</h2>
            <form method="post" action="{{ route('admin.ems.member.cert', $member) }}" class="field-row">@csrf @method('put')
                <div class="field"><input type="text" name="cert" maxlength="30" value="{{ old('cert', $member->ems_cert) }}" placeholder="Search by name"></div>
                <div class="field"><button class="btn btn-small btn-teal" type="submit">Save</button></div>
            </form>
            <p class="hint">With a number, they're searched by it. Without one, by name; the number is filled in once one match is listed with {{ $ours }}.</p>
        </div>

        <div class="card">
            <h2>Accepted exclusion</h2>
            @if ($member->ems_exclusion_at)
                <p>Not expected on the provider search, marked {{ $member->ems_exclusion_at->format('m/d/Y') }}{{ $member->emsExclusionBy ? ' by '.$member->emsExclusionBy->name : '' }}: {{ $member->ems_exclusion_note }}</p>
                @if ($member->ems_exclusion_found_at)
                    <p><strong style="color:var(--red)">Found on the search {{ $member->ems_exclusion_found_at->format('m/d/Y') }}.</strong></p>
                @endif
                <form method="post" action="{{ route('admin.ems.member.exclusion', $member) }}">@csrf @method('put')
                    <input type="hidden" name="excluded" value="0">
                    <button class="btn btn-small btn-outline" type="submit">Remove the exclusion</button>
                </form>
            @else
                <form method="post" action="{{ route('admin.ems.member.exclusion', $member) }}">@csrf @method('put')
                    <input type="hidden" name="excluded" value="1">
                    <div class="field"><label>Why they're not on the provider search</label><input type="text" name="note" maxlength="1000" required placeholder="Not EMS certified (dispatcher)"></div>
                    <button class="btn btn-small btn-teal" type="submit">Mark accepted exclusion</button>
                </form>
            @endif
            <p class="hint">They're still searched every time, and you're emailed if they turn up.</p>
        </div>
    </div>

    <div class="card">
        <h2>Other agencies</h2>
        @if ($affiliations->isEmpty())
            <p class="hint">None seen.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>Agency</th><th>First seen</th><th>Last seen</th><th>Review</th></tr>
                @foreach ($affiliations as $link)
                    @php([$label, $class] = $link->pill())
                    <tr>
                        <td>{{ $link->agency }}@if ($link->gone_at)<div class="hint">No longer listed ({{ $link->gone_at->format('m/d/Y') }})</div>@endif</td>
                        <td>{{ $link->first_seen_at->format('m/d/Y') }}</td>
                        <td>{{ $link->last_seen_at->format('m/d/Y') }}</td>
                        <td>
                            <span class="pill {{ $class }}">{{ $label }}</span>
                            @if ($link->reviewed_at)<div class="hint">{{ $link->reviewer?->name ?? 'Someone' }}, {{ $link->reviewed_at->format('m/d/Y') }}{{ $link->review_note ? ': '.$link->review_note : '' }}</div>@endif
                            <form method="post" action="{{ route('admin.ems.review', $link) }}" class="mt">@csrf
                                <input type="text" name="note" maxlength="1000" placeholder="Note (optional)" value="{{ $link->review_note }}">
                                @if ($link->status !== 'acceptable')<button class="btn btn-small btn-teal" name="status" value="acceptable">Acceptable</button>@endif
                                @if ($link->status !== 'unacceptable')<button class="btn btn-small" name="status" value="unacceptable">Unacceptable</button>@endif
                                @if ($link->status !== 'pending')<button class="btn btn-small btn-outline" name="status" value="pending">Back to review</button>@endif
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>

    <div class="card">
        <h2>Checks</h2>
        @if ($results->isEmpty())
            <p class="hint">Not checked yet.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>Date</th><th>Result</th><th>Agencies listed</th></tr>
                @foreach ($results as $result)
                    @php([$label, $class] = $result->pill())
                    <tr>
                        <td><a href="{{ route('admin.ems.show', $result->check) }}">{{ $result->check->created_at->format('m/d/Y') }}</a></td>
                        <td><span class="pill {{ $class }}">{{ $label }}</span>@if ($result->error)<div class="hint">{{ $result->error }}</div>@endif</td>
                        <td>{{ implode('; ', $result->agencies ?? []) }}</td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>
</div>
@endsection
