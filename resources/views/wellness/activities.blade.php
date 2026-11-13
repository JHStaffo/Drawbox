@extends('layouts.app', ['title' => 'Activities and rewards'])

@section('band')
    <h1>Activities and rewards</h1>
    <p>Send in visits, InBody scans and weigh-ins for extra points, and pick your gift cards.</p>
@endsection

@section('content')
<div class="stack">
    @php($toPick = $awards->where('status', 'pending')->whereNull('brand'))
    @if ($toPick->isNotEmpty())
        <div class="card locker-banner">
            <h2>Pick your gift card</h2>
            @foreach ($toPick as $award)
                <form method="post" action="{{ route('wellness.rewards.brand', $award) }}" class="row-actions mt">@csrf
                    <span><strong>{{ $award->label() }}</strong>@if ($award->test_period) <span class="pill">Test period</span>@endif</span>
                    <select name="brand" required style="max-width:220px">
                        <option value="">Choose…</option>
                        @foreach ($brands as $b)<option>{{ $b }}</option>@endforeach
                    </select>
                    <button class="btn btn-small" type="submit">Save</button>
                </form>
            @endforeach
        </div>
    @endif

    <div class="grid grid-2">
        <form class="card" method="post" action="{{ route('wellness.activities.store') }}" enctype="multipart/form-data" id="activity-form">@csrf
            <h2>{{ $fixing ? 'Fix and send again' : 'Send in an activity' }}</h2>
            @if ($fixing)
                <input type="hidden" name="fix" value="{{ $fixing->id }}">
                <p class="flash flash-error">Not approved: {{ $fixing->reject_reason }}</p>
            @endif
            <div class="field">
                <label for="activity">Activity</label>
                <select id="activity" name="activity" required>
                    <option value="">Choose…</option>
                    @foreach ($activities as $a)
                        <option value="{{ $a->key }}" data-note="{{ \App\Wellness\Submissions::needsUpload($a) ? 1 : 0 }}" @selected(old('activity', $fixing?->activity) === $a->key)>{{ $a->name }} ({{ $a->points }} points, up to {{ $a->capLabel() }})</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="day">Date it happened</label>
                <input id="day" type="date" name="day" required value="{{ old('day', $fixing?->day ? substr($fixing->day, 0, 10) : '') }}" min="{{ $oldest->toDateString() }}" max="{{ today()->toDateString() }}" style="max-width:200px">
            </div>
            <div class="field inbody-only">
                <div class="field-row">
                    <div><label for="body_fat_pct">Body fat (%)</label><input id="body_fat_pct" type="number" step="0.1" name="body_fat_pct" value="{{ old('body_fat_pct') }}" inputmode="decimal"></div>
                    <div><label for="muscle_lb">Skeletal muscle mass (lb)</label><input id="muscle_lb" type="number" step="0.1" name="muscle_lb" value="{{ old('muscle_lb') }}" inputmode="decimal"></div>
                </div>
                <div class="hint">Copy these two numbers from your InBody printout. Nobody else in the program sees them.</div>
            </div>
            <div class="field note-field">
                <label for="note">Signed note or printout</label>
                <input id="note" type="file" name="note" accept="image/*,.pdf,.heic">
                <div class="hint">A photo or PDF of a simple signed attendance note: your name, the date and the provider's signature. Don't include a diagnosis, treatment or test results. For InBody, a photo of the printout.</div>
            </div>
            <div class="field">
                <label for="comment">Anything the reviewer should know (optional)</label>
                <input id="comment" type="text" name="comment" maxlength="500" value="{{ old('comment') }}">
            </div>
            <button class="btn" type="submit">Send</button>
        </form>

        <div class="card">
            <h2>Your rewards</h2>
            @forelse ($awards as $award)
                <p>{{ $award->label() }} · <span @class(['pill', 'pill-green' => $award->status === 'sent'])>{{ $award->statusLabel() }}</span>@if ($award->brand) · {{ $award->brand }}@endif @if ($award->test_period)<span class="pill">Test period</span>@endif</p>
            @empty
                <p class="empty">Earn all {{ \App\Wellness\Scoring::weeklyTarget() }} daily-habit points in a Sunday to Saturday week for a {{ money((int) \App\Models\Setting::get('wellness_weekly_reward_cents')) }} gift card. Rewards appear here a few days after the week ends.</p>
            @endforelse
        </div>
    </div>

    <div class="card">
        <h2>What you've sent</h2>
        @if ($submissions->isEmpty())
            <p class="empty">Nothing yet.</p>
        @else
            <div class="table-wrap">
            <table>
                <thead><tr><th>Activity</th><th>Date</th><th>Status</th><th class="num">Points</th><th></th></tr></thead>
                <tbody>
                @foreach ($submissions as $s)
                    <tr>
                        <td>{{ $activities->get($s->activity)?->name ?? $s->activity }}</td>
                        <td>{{ $s->dayLabel() }}</td>
                        <td>
                            <span @class(['pill', 'pill-green' => $s->status === 'approved', 'pill-red' => $s->status === 'rejected', 'pill-teal' => $s->status === 'pending'])>{{ $s->statusLabel() }}</span>
                            @if ($s->status === 'rejected')<div class="hint">{{ $s->reject_reason }}</div>@endif
                            @if ($s->points_note)<div class="hint">{{ $s->points_note }}</div>@endif
                        </td>
                        <td class="num">{{ $s->status === 'approved' ? $s->points : '' }}</td>
                        <td class="right">
                            <div class="row-actions" style="justify-content:flex-end">
                                @if ($s->note_path)<a class="btn btn-outline btn-small" href="{{ route('wellness.activities.note', $s) }}" target="_blank">Note</a>@endif
                                @if ($s->status === 'pending')
                                    <form method="post" action="{{ route('wellness.activities.withdraw', $s) }}">@csrf<button class="btn btn-outline btn-small" type="submit">Withdraw</button></form>
                                @elseif ($s->status === 'rejected')
                                    <a class="btn btn-small" href="{{ route('wellness.activities', ['fix' => $s->id]) }}">Fix and resend</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Show the InBody numbers and the upload only for activities that use them.
    (function () {
        var select = document.getElementById('activity');
        function update() {
            var opt = select.options[select.selectedIndex];
            document.querySelector('.inbody-only').hidden = select.value !== 'inbody';
            var needsNote = opt && opt.dataset.note === '1';
            document.querySelector('.note-field').hidden = select.value !== '' && !needsNote;
            document.getElementById('note').required = needsNote;
        }
        select.addEventListener('change', update);
        update();
    })();
</script>
@endpush
