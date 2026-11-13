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
            <form method="post" action="{{ route('protocols.approvals.batch') }}" id="batch">@csrf
            <div class="table-wrap"><table>
                <tr>
                    <th style="width:36px"><input type="checkbox" id="select-all" aria-label="Select all"></th>
                    <th>Protocol</th><th>Change</th><th>Sent</th><th></th>
                </tr>
                @foreach ($waiting as $v)
                    <tr>
                        <td><input type="checkbox" name="versions[]" value="{{ $v->id }}" class="pick" aria-label="Select {{ $v->fullTitle() }}" @checked(in_array($v->id, old('versions', [])))></td>
                        <td>{{ $v->fullTitle() }}<div class="hint">{{ $v->category_name ?? 'No category' }} · {{ $v->agesLabel() }}</div></td>
                        <td>{{ $v->protocol->approved ? 'Revision (version '.$v->version.' replaces '.$v->protocol->approved->version.')' : 'New protocol' }}</td>
                        <td>{{ $v->submitted_at?->format('M j, Y g:i A') }}<div class="hint">{{ $v->submitter?->name }}</div></td>
                        <td><a class="btn btn-small" href="{{ route('protocols.approvals.show', $v) }}">Review</a></td>
                    </tr>
                @endforeach
            </table></div>
            <div class="batch-approve mt">
                <h3 style="margin:0 0 6px">Approve the ones you ticked</h3>
                <p class="hint" style="margin-top:0">Open each with Review first. One signature approves every ticked protocol, and each one goes in the book right away. To send one back, open it with Review.</p>
                <label class="check"><input type="checkbox" name="confirm" value="1" @checked(old('confirm'))> I have reviewed every ticked protocol and approve them as Medical Director.</label>
                <div class="field mt">
                    <label for="batch-signature">Type your name to sign</label>
                    <input id="batch-signature" type="text" name="signature" value="{{ old('signature') }}" maxlength="120" placeholder="{{ auth()->user()->name }}" style="max-width:320px">
                </div>
                <button class="btn" type="submit" id="batch-button" disabled>Approve selected</button>
            </div>
            </form>
            <script>
            (function () {
                var all = document.getElementById('select-all'), picks = [].slice.call(document.querySelectorAll('#batch .pick')), button = document.getElementById('batch-button');
                function update() {
                    var n = picks.filter(function (p) { return p.checked; }).length;
                    all.checked = n === picks.length; all.indeterminate = n > 0 && n < picks.length;
                    button.disabled = n === 0;
                    button.textContent = n === 0 ? 'Approve selected' : n === picks.length && n > 1 ? 'Approve all ' + n : 'Approve ' + n + ' selected';
                }
                all.addEventListener('change', function () { picks.forEach(function (p) { p.checked = all.checked; }); update(); });
                picks.forEach(function (p) { p.addEventListener('change', update); });
                update();
            })();
            </script>
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
