<form method="post" action="{{ $toggle }}">@csrf
    <button class="btn btn-small btn-outline" type="submit">{{ $hidden ? 'Unhide' : 'Hide' }}</button>
</form>
<form method="post" action="{{ $destroy }}" onsubmit="return confirm(@js('Delete '.$name.' for good? Past winners keep it in their history, but it can\'t be brought back.'))">@csrf @method('delete')
    <button class="btn btn-small btn-outline" style="color:var(--red)" type="submit">Delete</button>
</form>
