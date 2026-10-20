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

    <div class="card">
        <h2>Roster ({{ $users->where('active', true)->count() }} active)</h2>
        <div class="table-wrap"><table>
            <tr><th>Name</th><th>Email</th><th>Employee #</th><th>Role</th><th>Active</th><th></th></tr>
            @foreach ($users as $user)
                <tr>
                    <td><form id="u{{ $user->id }}" method="post" action="{{ route('admin.users.update', $user) }}">@csrf @method('put')</form>
                        <input form="u{{ $user->id }}" type="text" name="name" value="{{ $user->name }}" required></td>
                    <td><input form="u{{ $user->id }}" type="email" name="email" value="{{ $user->email }}" required></td>
                    <td><input form="u{{ $user->id }}" type="text" name="employee_number" value="{{ $user->employee_number }}" style="width:100px"></td>
                    <td><select form="u{{ $user->id }}" name="role">@foreach (\App\Models\User::ROLES as $role)<option value="{{ $role }}" @selected($user->role === $role)>{{ ucfirst($role) }}</option>@endforeach</select></td>
                    <td><input form="u{{ $user->id }}" type="hidden" name="active" value="0"><input form="u{{ $user->id }}" type="checkbox" name="active" value="1" @checked($user->active)></td>
                    <td><button form="u{{ $user->id }}" class="btn btn-small btn-teal" type="submit">Save</button></td>
                </tr>
            @endforeach
        </table></div>
    </div>
</div>
@endsection
