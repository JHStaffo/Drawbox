<div class="field perm-filter" style="max-width:320px">
    <label>Find a permission</label>
    <input type="search" placeholder="Type to filter" autocomplete="off" oninput="filterPerms(this)" onkeydown="if (event.key === 'Enter') event.preventDefault()">
</div>
<div class="perm-groups">
    @foreach (\App\Models\PermissionProfile::GROUPS as $group => $perms)
        <fieldset class="perm-group">
            <legend>{{ $group }}</legend>
            @foreach ($perms as $key => $label)
                @if (in_array($key, $inherited, true))
                    <label class="check inherited" data-perm="{{ Str::lower($group.' '.$label) }}"><input type="checkbox" checked disabled> <span>{{ $label }} <span class="hint">from {{ $from }}</span></span></label>
                @else
                    <label class="check" data-perm="{{ Str::lower($group.' '.$label) }}"><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, $picked, true))> {{ $label }}</label>
                @endif
            @endforeach
        </fieldset>
    @endforeach
</div>
@once
<script>
    // Hide permissions that don't match, and any group left empty.
    function filterPerms(input) {
        var q = input.value.trim().toLowerCase();
        var form = input.closest('form');
        form.querySelectorAll('.perm-group').forEach(function (group) {
            var shown = 0;
            group.querySelectorAll('[data-perm]').forEach(function (el) {
                var match = !q || el.dataset.perm.indexOf(q) !== -1;
                el.style.display = match ? '' : 'none';
                if (match) shown++;
            });
            group.style.display = shown ? '' : 'none';
        });
    }
</script>
@endonce
