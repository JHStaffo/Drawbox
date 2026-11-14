@extends('layouts.app', ['title' => 'EMS Affiliations'])

@if ($active)
    @push('scripts')<script>setTimeout(() => location.reload(), 15000);</script>@endpush
@endif

@section('band')
    <h1>EMS Affiliations</h1>
    <p>Every active Team Member searched on the Virginia Office of EMS provider search: listed with {{ $ours }}, and any other agency they're listed with. It runs on its own on the 16th of each month, and every check is kept.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        @if ($active)
            <p><span class="pill pill-teal">{{ $active->status === 'queued' ? 'Starting' : 'Checking' }}</span>
                {{ $active->members_done }} of {{ $active->members_total }} Team Members searched. This page refreshes on its own.</p>
        @else
            <form method="post" action="{{ route('admin.ems.store') }}" onsubmit="this.querySelector('button').disabled = true;">@csrf
                <button class="btn" type="submit">Check now</button>
            </form>
            <p class="hint mt">Searches each active Team Member by certification # (or by name until their number is known) and compares the agencies listed.</p>
        @endif
    </div>

    @if ($queue->isNotEmpty() || $flagged->isNotEmpty() || $foundExclusions->isNotEmpty())
        <div class="card">
            <h2>Needs review</h2>
            @if ($queue->isNotEmpty())
                <div class="table-wrap"><table>
                    <tr><th>Team Member</th><th>Agency</th><th>First seen</th><th>Review</th></tr>
                    @foreach ($queue as $link)
                        <tr>
                            <td><a href="{{ route('admin.ems.member', $link->user) }}">{{ $link->user->name }}</a></td>
                            <td>{{ $link->agency }}</td>
                            <td>{{ $link->first_seen_at->format('m/d/Y') }}</td>
                            <td>
                                <form method="post" action="{{ route('admin.ems.review', $link) }}" class="field-row">@csrf
                                    <div class="field"><input type="text" name="note" maxlength="1000" placeholder="Note (optional)"></div>
                                    <div class="field">
                                        <button class="btn btn-small btn-teal" name="status" value="acceptable">Acceptable</button>
                                        <button class="btn btn-small" name="status" value="unacceptable">Unacceptable</button>
                                        <label class="hint"><input type="checkbox" name="everyone" value="1"> and add the agency to the unacceptable list</label>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </table></div>
            @endif
            @if ($flagged->isNotEmpty())
                <h3 class="mt">Unacceptable affiliations still listed</h3>
                <ul style="margin:0;padding-left:20px">
                    @foreach ($flagged as $link)
                        <li><a href="{{ route('admin.ems.member', $link->user) }}">{{ $link->user->name }}</a>: <strong style="color:var(--red)">{{ $link->agency }}</strong> <span class="hint">({{ $link->auto_flagged ? 'on the list' : 'reviewed' }}, last seen {{ $link->last_seen_at->format('m/d/Y') }})</span></li>
                    @endforeach
                </ul>
            @endif
            @if ($foundExclusions->isNotEmpty())
                <h3 class="mt">Accepted exclusions found on the search</h3>
                <ul style="margin:0;padding-left:20px">
                    @foreach ($foundExclusions as $member)
                        <li><a href="{{ route('admin.ems.member', $member) }}">{{ $member->name }}</a> <span class="hint">found {{ $member->ems_exclusion_found_at->format('m/d/Y') }}</span></li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="card">
        <h2>Checks</h2>
        @if ($checks->isEmpty())
            <p class="hint">No checks yet.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>Date checked</th><th>Result</th><th>Team Members</th><th>Run by</th></tr>
                @foreach ($checks as $check)
                    <tr>
                        <td>
                            @if ($check->status === 'done')
                                <a href="{{ route('admin.ems.show', $check) }}">{{ $check->created_at->format('m/d/Y g:i A') }}</a>
                            @else
                                {{ $check->created_at->format('m/d/Y g:i A') }}
                            @endif
                        </td>
                        <td>
                            @if ($check->status === 'failed')
                                <span class="pill pill-amber">Didn't finish</span> <span class="hint">{{ $check->error }}</span>
                            @elseif ($check->isActive())
                                <span class="pill pill-teal">Running</span>
                            @else
                                @if ($check->unacceptable_count)<div><strong style="color:var(--red)">{{ $check->unacceptable_count }} unacceptable {{ Str::plural('affiliation', $check->unacceptable_count) }}</strong></div>@endif
                                @if ($check->missing_ours_count)<div><strong style="color:var(--red)">{{ $check->missing_ours_count }} not listed with us</strong></div>@endif
                                @if ($check->review_count)<div><span class="pill pill-amber">{{ $check->review_count }} to review</span></div>@endif
                                @if ($check->not_found_count)<div><span class="pill pill-amber">{{ $check->not_found_count }} not found</span></div>@endif
                                @if ($check->problem_count)<div><span class="pill pill-amber">{{ $check->problem_count }} couldn't be matched</span></div>@endif
                                @if (! $check->attentionCount())
                                    All listed with us
                                @endif
                            @endif
                        </td>
                        <td>{{ $check->members_total }}</td>
                        <td>{{ $check->runner?->name ?? 'Monthly (automatic)' }}</td>
                    </tr>
                @endforeach
            </table></div>
            {{ $checks->links() }}
        @endif
    </div>

    <div class="card">
        <h2>Unacceptable agencies</h2>
        <p class="hint">A Team Member listed with one of these is flagged right away, without a review.</p>
        @if ($unacceptable->isNotEmpty())
            <div class="table-wrap"><table>
                <tr><th>Agency</th><th>Note</th><th>Added</th><th></th></tr>
                @foreach ($unacceptable as $agency)
                    <tr>
                        <td>{{ $agency->name }}</td>
                        <td>{{ $agency->note }}</td>
                        <td class="hint">{{ $agency->created_at->format('m/d/Y') }}{{ $agency->adder ? ' by '.$agency->adder->name : '' }}</td>
                        <td>
                            <form method="post" action="{{ route('admin.ems.unacceptable.destroy', $agency) }}">@csrf @method('delete')
                                <button class="btn btn-small btn-outline" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table></div>
        @endif
        <form method="post" action="{{ route('admin.ems.unacceptable.store') }}" class="mt">@csrf
            <div class="field-row">
                <div class="field"><label>Agency</label><input type="text" name="name" maxlength="150" required list="ems-seen-agencies" value="{{ old('name') }}"></div>
                <div class="field"><label>Note (optional)</label><input type="text" name="note" maxlength="1000" value="{{ old('note') }}"></div>
            </div>
            <datalist id="ems-seen-agencies">@foreach ($seenAgencies as $name)<option value="{{ $name }}">@endforeach</datalist>
            <button class="btn btn-small btn-teal" type="submit">Add</button>
        </form>
    </div>

    <div class="card">
        <h2>Try the search</h2>
        <p class="hint">Searches the provider site for one name or certification # and shows what came back. Nothing is saved.</p>
        <form method="post" action="{{ route('admin.ems.test') }}">@csrf
            <div class="field-row">
                <div class="field"><label>First name</label><input type="text" name="first" maxlength="60" value="{{ old('first') }}"></div>
                <div class="field"><label>Last name</label><input type="text" name="last" maxlength="60" value="{{ old('last') }}"></div>
                <div class="field"><label>or Certification #</label><input type="text" name="cert" maxlength="30" value="{{ old('cert') }}"></div>
            </div>
            <button class="btn btn-small btn-teal" type="submit">Search</button>
        </form>
        @if ($test = session('ems_test'))
            <div class="mt">
                <strong>{{ $test['what'] }}</strong>:
                @if ($test['providers'] === null)
                    the search didn't work.
                @elseif (! $test['providers'])
                    nobody found.
                @else
                    {{ count($test['providers']) }} found.
                    <ul style="margin:6px 0;padding-left:20px">
                        @foreach ($test['providers'] as $p)
                            <li>{{ $p['name'] }}@if ($p['cert']) · #{{ $p['cert'] }}@endif @if ($p['level']) · {{ $p['level'] }}@endif @if ($p['expires']) · expires {{ $p['expires'] }}@endif
                                · {{ $p['agencies'] ? implode('; ', $p['agencies']) : 'no agencies listed' }}</li>
                        @endforeach
                    </ul>
                @endif
                <details class="hint"><summary>What the site sent</summary>
                    Boxes found: {{ implode(', ', $test['trace']['boxes'] ?? []) ?: 'none' }} ·
                    button: {{ $test['trace']['request'] ?? '?' }} ·
                    columns: {{ implode(', ', $test['trace']['headings'] ?? []) ?: 'none' }} ·
                    rows: {{ $test['trace']['rows'] ?? 0 }} ·
                    page: {{ number_format($test['trace']['page_bytes'] ?? 0) }} bytes, {{ $test['trace']['region_codes'] ?? 0 }} region codes, salt {{ $test['trace']['salt'] ?? '?' }}, checksum {{ $test['trace']['protected'] ?? '?' }} ·
                    responses: {{ implode(', ', $test['trace']['steps'] ?? []) }} ({{ number_format($test['trace']['bytes'] ?? 0) }} bytes) ·
                    download the <a href="{{ route('admin.ems.last', 'page') }}">search page</a> and the <a href="{{ route('admin.ems.last', 'result') }}">results</a>
                </details>
            </div>
        @endif
    </div>

    <div class="card">
        <h2>Our agency</h2>
        <form method="post" action="{{ route('admin.ems.agency') }}" class="field-row">@csrf @method('put')
            <div class="field"><label>As the provider search lists it</label><input type="text" name="agency" maxlength="150" required value="{{ $ours }}"></div>
            <div class="field"><label>&nbsp;</label><button class="btn btn-small btn-teal" type="submit">Save</button></div>
        </form>
    </div>
</div>
@endsection
