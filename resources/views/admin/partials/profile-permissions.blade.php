<div class="perm-groups">
    @foreach (\App\Models\PermissionProfile::GROUPS as $group => $perms)
        <fieldset class="perm-group">
            <legend>{{ $group }}</legend>
            @foreach ($perms as $key => $label)
                @if (in_array($key, $inherited, true))
                    <label class="check inherited"><input type="checkbox" checked disabled> <span>{{ $label }} <span class="hint">from {{ $from }}</span></span></label>
                @else
                    <label class="check"><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, $picked, true))> {{ $label }}</label>
                @endif
            @endforeach
        </fieldset>
    @endforeach
</div>
