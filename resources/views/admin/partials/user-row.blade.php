<tr>
    <td><form id="u{{ $user->id }}" method="post" action="{{ route('admin.users.update', $user) }}">@csrf @method('put')</form>
        <input form="u{{ $user->id }}" type="text" name="name" value="{{ $user->name }}" required></td>
    <td><input form="u{{ $user->id }}" type="email" name="email" value="{{ $user->email }}" required></td>
    <td><input form="u{{ $user->id }}" type="text" name="employee_number" value="{{ $user->employee_number }}" style="width:100px"></td>
    <td><select form="u{{ $user->id }}" name="role">@foreach (\App\Models\User::ROLES as $role)<option value="{{ $role }}" @selected($user->role === $role)>{{ ucfirst($role) }}</option>@endforeach</select></td>
    <td><input form="u{{ $user->id }}" type="hidden" name="active" value="0"><input form="u{{ $user->id }}" type="checkbox" name="active" value="1" @checked($user->active)></td>
    <td><button form="u{{ $user->id }}" class="btn btn-small btn-teal" type="submit">Save</button></td>
</tr>
