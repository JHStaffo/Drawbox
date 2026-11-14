{{-- SSN and date of birth: last 4 and "on file" for people who can see or edit them, the full values one look at a time, and change forms. Never prefilled. --}}
<td class="ssn-cell">
    <span class="ssn-value" data-masked="{{ $user->hasSsn() ? $user->ssnMasked() : 'No SSN' }}">{{ $user->hasSsn() ? $user->ssnMasked() : 'No SSN' }}</span>
    <div class="hint dob-value" data-masked="{{ $user->hasDob() ? 'DOB on file' : 'No DOB' }}">{{ $user->hasDob() ? 'DOB on file' : 'No DOB' }}</div>
    @if ($canSeeSsn && $ssnReady && ($user->hasSsn() || $user->hasDob()))
        <button type="button" class="btn btn-small btn-outline ssn-show" data-url="{{ route('admin.users.ssn.reveal', $user) }}" aria-label="Show {{ $user->name }}'s SSN and date of birth">Show</button>
    @endif
    @if ($canEditSsn && $ssnReady && auth()->user()->canManage($user))
        <details style="margin-top:4px">
            <summary>Change</summary>
            <form method="post" action="{{ route('admin.users.ssn.update', $user) }}" style="margin-top:6px">@csrf @method('put')
                <input type="password" name="ssn" inputmode="numeric" autocomplete="off" placeholder="SSN ###-##-####" aria-label="SSN for {{ $user->name }}" style="width:150px" required>
                <button class="btn btn-small btn-teal" type="submit">Save SSN</button>
            </form>
            <form method="post" action="{{ route('admin.users.dob.update', $user) }}" style="margin-top:6px">@csrf @method('put')
                <input type="date" name="dob" autocomplete="off" aria-label="Date of birth for {{ $user->name }}" style="width:150px" required>
                <button class="btn btn-small btn-teal" type="submit">Save DOB</button>
            </form>
            @if ($user->hasSsn())
                <form method="post" action="{{ route('admin.users.ssn.update', $user) }}" style="margin-top:6px;display:inline-block" onsubmit="return confirm('Remove {{ addslashes($user->name) }}\'s SSN?')">@csrf @method('put')
                    <input type="hidden" name="remove" value="1">
                    <button class="btn btn-small btn-outline" style="color:var(--red)" type="submit">Remove SSN</button>
                </form>
            @endif
            @if ($user->hasDob())
                <form method="post" action="{{ route('admin.users.dob.update', $user) }}" style="margin-top:6px;display:inline-block" onsubmit="return confirm('Remove {{ addslashes($user->name) }}\'s date of birth?')">@csrf @method('put')
                    <input type="hidden" name="remove" value="1">
                    <button class="btn btn-small btn-outline" style="color:var(--red)" type="submit">Remove DOB</button>
                </form>
            @endif
        </details>
    @endif
</td>
