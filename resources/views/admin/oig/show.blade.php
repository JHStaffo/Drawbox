@extends('layouts.app', ['title' => 'OIG check '.$check->created_at->format('m/d/Y')])

@section('band')
    <h1>OIG check, {{ $check->created_at->format('m/d/Y g:i A') }}</h1>
    <p>
        @if ($check->excluded_count)
            <strong style="color:#fff;background:var(--red);padding:2px 8px;border-radius:6px">{{ $check->excluded_count }} {{ Str::plural('exclusion', $check->excluded_count) }} found</strong>
        @else
            0 exclusions found
        @endif
        · {{ $check->members_checked }} active Team Members · OIG list dated {{ $check->list_updated_at?->format('m/d/Y') ?? 'unknown' }} · run by {{ $check->runner?->name ?? 'the monthly automatic check' }}
    </p>
@endsection

@section('content')
<div class="stack">
    <p><a href="{{ route('admin.oig') }}">← All checks</a></p>

    @if ($results->contains(fn ($r) => $r->matches))
        <div class="card">
            <h2>Checking a match by hand</h2>
            <ol class="hint" style="margin:0;padding-left:20px">
                <li>Open the <a href="https://exclusions.oig.hhs.gov/" target="_blank" rel="noopener">OIG exclusions search</a> and search the Team Member's first and last name.</li>
                <li>On the matching entry, press Verify and enter their SSN (Show it under Admin &gt; Team Members).</li>
                <li>Save or print the result page as a PDF, then record the outcome below with a short note and the PDF.</li>
            </ol>
        </div>
    @endif

    <div class="card">
        <div class="table-wrap"><table>
            <tr><th>Team Member</th><th>Date of birth</th><th>Result</th></tr>
            @foreach ($results as $result)
                <tr>
                    <td>{{ $result->name }}</td>
                    <td>{{ $result->dob_masked ?? '' }}</td>
                    <td>
                        <span class="pill {{ $result->pillClass() }}">{{ $result->label() }}</span>
                        @if ($result->reviewed_at)
                            <div class="hint" style="margin-top:4px">Checked by hand by {{ $result->reviewer?->name ?? 'someone' }} on {{ $result->reviewed_at->format('m/d/Y') }}: {{ $result->review_note }}
                                @if ($result->review_file)· <a href="{{ route('admin.oig.file', $result) }}" target="_blank">verification file</a>@endif
                            </div>
                        @elseif ($result->status === 'excluded')
                            <div class="hint" style="margin-top:4px">Same name and date of birth as an excluded person. Confirm it on the OIG website with the SSN.</div>
                        @elseif ($result->status === 'review')
                            <div class="hint" style="margin-top:4px">Same name as an excluded person, but there's no date of birth to compare.</div>
                        @elseif ($result->matches)
                            <div class="hint" style="margin-top:4px">Same name as an excluded person with a different date of birth.</div>
                        @endif
                        @if ($result->matches)
                            <details style="margin-top:4px">
                                <summary>{{ count($result->matches) === 1 ? 'OIG entry' : count($result->matches).' OIG entries' }} and manual check</summary>
                                <ul class="hint" style="margin:6px 0;padding-left:20px">
                                    @foreach ($result->matches as $m)
                                        <li>{{ $m['name'] }} · born {{ $m['dob'] ? \Carbon\Carbon::parse($m['dob'])->format('m/d').'/XX' : 'not listed' }}@if ($m['same_dob'] === true) <strong style="color:var(--red)">(same date of birth)</strong>@endif · {{ $m['specialty'] }} · {{ $m['city'] }} · excluded {{ $m['excluded_on'] ? \Carbon\Carbon::parse($m['excluded_on'])->format('m/d/Y') : '' }} ({{ $m['type'] }})</li>
                                    @endforeach
                                </ul>
                                <form method="post" action="{{ route('admin.oig.review', $result) }}" enctype="multipart/form-data">@csrf
                                    <div class="field-row">
                                        <div class="field"><label>Outcome on the OIG website</label>
                                            <select name="outcome" required>
                                                <option value="">Choose…</option>
                                                <option value="not_excluded" @selected($result->review_outcome === 'not_excluded')>Not excluded (SSN didn't match, different person)</option>
                                                <option value="excluded" @selected($result->review_outcome === 'excluded')>Excluded (SSN matched)</option>
                                            </select>
                                        </div>
                                        <div class="field"><label>How it was checked</label><input type="text" name="note" maxlength="2000" required placeholder="Verified with SSN on exclusions.oig.hhs.gov" value="{{ $result->review_note }}"></div>
                                        <div class="field"><label>OIG result page (PDF or picture)</label><input type="file" name="file" accept=".pdf,.png,.jpg,.jpeg"></div>
                                    </div>
                                    <button class="btn btn-small btn-teal" type="submit">Save manual check</button>
                                </form>
                            </details>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table></div>
    </div>
</div>
@endsection
