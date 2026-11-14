{{-- SSN cell: last 4 for people who can see or edit SSNs, the full number one look at a time, and a change form. Never prefilled. --}}
<td class="ssn-cell">
    @if ($user->hasSsn())
        <span class="ssn-value" data-masked="{{ $user->ssnMasked() }}">{{ $user->ssnMasked() }}</span>
        @if ($canSeeSsn && $ssnReady)
            <button type="button" class="btn btn-small btn-outline ssn-show" data-url="{{ route('admin.users.ssn.reveal', $user) }}" aria-label="Show {{ $user->name }}'s SSN">Show</button>
        @endif
    @else
        <span class="hint">None</span>
    @endif
    @if ($canEditSsn && $ssnReady && auth()->user()->canManage($user))
        <details style="margin-top:4px">
            <summary>{{ $user->hasSsn() ? 'Change' : 'Add' }}</summary>
            <form method="post" action="{{ route('admin.users.ssn.update', $user) }}" style="margin-top:6px">@csrf @method('put')
                <input type="password" name="ssn" inputmode="numeric" autocomplete="off" placeholder="###-##-####" aria-label="SSN for {{ $user->name }}" style="width:130px" required>
                <button class="btn btn-small btn-teal" type="submit">Save</button>
            </form>
            @if ($user->hasSsn())
                <form method="post" action="{{ route('admin.users.ssn.update', $user) }}" style="margin-top:6px" onsubmit="return confirm('Remove {{ addslashes($user->name) }}\'s SSN?')">@csrf @method('put')
                    <input type="hidden" name="remove" value="1">
                    <button class="btn btn-small btn-outline" style="color:var(--red)" type="submit">Remove</button>
                </form>
            @endif
        </details>
    @endif
</td>
