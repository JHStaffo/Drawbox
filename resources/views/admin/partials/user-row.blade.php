<tr>
    <td><form id="u{{ $user->id }}" method="post" action="{{ route('admin.users.update', $user) }}">@csrf @method('put')</form>
        <input form="u{{ $user->id }}" type="text" name="name" value="{{ $user->name }}" required></td>
    <td><input form="u{{ $user->id }}" type="email" name="email" value="{{ $user->email }}" required></td>
    <td><input form="u{{ $user->id }}" type="text" name="employee_number" value="{{ $user->employee_number }}" style="width:100px"></td>
    <td><select form="u{{ $user->id }}" name="role">@foreach (\App\Models\User::ROLES as $role)<option value="{{ $role }}" @selected($user->role === $role)>{{ \App\Models\User::ROLE_LABELS[$role] }}</option>@endforeach</select></td>
    <td><input form="u{{ $user->id }}" type="hidden" name="active" value="0"><input form="u{{ $user->id }}" type="checkbox" name="active" value="1" @checked($user->active)></td>
    <td><div class="row-actions" style="flex-wrap:nowrap">
        <button form="u{{ $user->id }}" class="btn btn-small btn-teal" type="submit">Save</button>
        @unless ($user->is(auth()->user()))
            <form method="post" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('Delete this team member? Only do this for someone added by mistake. To keep their history, uncheck Active instead.')">@csrf @method('delete')
                <button class="btn btn-small btn-outline" style="color:var(--red)" type="submit">Delete</button>
            </form>
        @endunless
    </div></td>
</tr>
