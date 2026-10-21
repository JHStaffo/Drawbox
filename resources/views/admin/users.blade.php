@extends('layouts.app', ['title' => 'Employees'])

@section('band')
    <h1>Employees</h1>
    <p>Everyone on this list can sign in with their email. Turn someone off when they leave, and their history stays for tax records.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <h2>Add an employee</h2>
        <form method="post" action="{{ route('admin.users.store') }}">@csrf
            <div class="field-row">
                <div class="field"><label>Name</label><input type="text" name="name" value="{{ old('name') }}" required></div>
                <div class="field"><label>Email</label><input type="email" name="email" value="{{ old('email') }}" required></div>
                <div class="field"><label>Employee #</label><input type="text" name="employee_number" value="{{ old('employee_number') }}"></div>
                <div class="field"><label>Role</label>
                    <select name="role">@foreach (\App\Models\User::ROLES as $role)<option value="{{ $role }}">{{ ucfirst($role) }}</option>@endforeach</select>
                </div>
            </div>
            <button class="btn" type="submit">Add employee</button>
        </form>
    </div>

    @php($active = $users->where('active', true))
    @php($inactive = $users->where('active', false))
    <div class="card">
        <h2>Roster ({{ $active->count() }} active)</h2>
        <div class="table-wrap"><table>
            <tr><th>Name</th><th>Email</th><th>Employee #</th><th>Role</th><th>Active</th><th></th></tr>
            @foreach ($active as $user)
                @include('admin.partials.user-row')
            @endforeach
        </table></div>
    </div>

    @if ($inactive->isNotEmpty())
        <div class="card">
            <details>
                <summary>Inactive employees ({{ $inactive->count() }})</summary>
                <p class="hint mt">They can't sign in or be credited, but their prize history stays for tax records. Check Active and Save to bring someone back.</p>
                <div class="table-wrap"><table>
                    <tr><th>Name</th><th>Email</th><th>Employee #</th><th>Role</th><th>Active</th><th></th></tr>
                    @foreach ($inactive as $user)
                        @include('admin.partials.user-row')
                    @endforeach
                </table></div>
            </details>
        </div>
    @endif
</div>
@endsection
