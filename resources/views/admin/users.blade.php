@extends('layouts.app', ['title' => 'Team Members'])

@section('band')
    <h1>Team Members</h1>
    <p>Everyone on this list can sign in with their email. Mobile numbers are kept for text messages later on. Turn someone off when they leave, and their history stays for tax records.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <h2>Add a team member</h2>
        <form method="post" action="{{ route('admin.users.store') }}">@csrf
            <div class="field-row">
                <div class="field"><label>Name</label><input type="text" name="name" value="{{ old('name') }}" required></div>
                <div class="field"><label>Email</label><input type="email" name="email" value="{{ old('email') }}" required></div>
                <div class="field"><label>Mobile phone</label><input type="tel" name="phone" value="{{ old('phone') }}" placeholder="(757) 555-1234" autocomplete="off"></div>
                <div class="field"><label>Team Member #</label><input type="text" name="employee_number" value="{{ old('employee_number') }}"></div>
                @if ($remoteLockPin)
                    <div class="field"><label>Door PIN (RemoteLock)</label><input type="text" name="remotelock_pin" inputmode="numeric" pattern="\d{4,8}" maxlength="8" placeholder="Leave blank for a random one" autocomplete="off">
                        @error('remotelock_pin')<div class="error">{{ $message }}</div>@enderror</div>
                @endif
                <div class="field"><label>Permission profile</label>
                    <select name="permission_profile_id">@foreach ($grantable as $p)<option value="{{ $p->id }}" @selected(old('permission_profile_id') ? old('permission_profile_id') == $p->id : $p->is_default)>{{ $p->label() }}</option>@endforeach</select>
                </div>
            </div>
            <button class="btn" type="submit">Add team member</button>
        </form>
    </div>

    <div class="card">
        <h2>Import from a CSV</h2>
        <p class="hint">Use a file with First Name, Last Name and Email columns, plus an optional Phone column. Everyone gets the {{ $profiles->firstWhere('is_default', true)?->label() ?? 'default' }} profile, and anyone whose email is already on the roster is skipped. Someone already on the roster with no phone number gets the one in the file.</p>
        <form method="post" action="{{ route('admin.users.import') }}" enctype="multipart/form-data">@csrf
            <div class="field"><input type="file" name="file" accept=".csv,text/csv" required></div>
            <button class="btn btn-teal" type="submit">Import team members</button>
        </form>
    </div>

    @php($active = $users->where('active', true))
    @php($inactive = $users->where('active', false))
    <div class="card">
        <h2>Roster ({{ $active->count() }} active)</h2>
        <div class="table-wrap"><table>
            <tr><th>Name</th><th>Email</th><th>Mobile phone</th><th>Team Member #</th><th>Profile</th><th>Active</th><th></th></tr>
            @foreach ($active as $user)
                @include('admin.partials.user-row')
            @endforeach
        </table></div>
    </div>

    @if ($inactive->isNotEmpty())
        <div class="card">
            <details>
                <summary>Inactive team members ({{ $inactive->count() }})</summary>
                <p class="hint mt">They can't sign in or be credited, but their prize history stays for tax records. Check Active and Save to bring someone back.</p>
                <div class="table-wrap"><table>
                    <tr><th>Name</th><th>Email</th><th>Mobile phone</th><th>Team Member #</th><th>Profile</th><th>Active</th><th></th></tr>
                    @foreach ($inactive as $user)
                        @include('admin.partials.user-row')
                    @endforeach
                </table></div>
            </details>
        </div>
    @endif
</div>
@endsection
