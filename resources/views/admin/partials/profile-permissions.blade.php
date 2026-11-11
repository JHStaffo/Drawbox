<div class="perm-groups">
    @foreach (\App\Models\PermissionProfile::GROUPS as $group => $perms)
        <fieldset class="perm-group">
            <legend>{{ $group }}</legend>
            @foreach ($perms as $key => $label)
                <label class="check"><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, $picked, true))> {{ $label }}</label>
            @endforeach
        </fieldset>
    @endforeach
</div>
