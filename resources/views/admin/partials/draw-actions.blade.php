@if (auth()->user()->hasRole('admin'))
    <a class="btn btn-small btn-outline" href="{{ route('admin.draws.edit', $draw) }}">Edit</a>
    <form method="post" action="{{ route('admin.draws.destroy', $draw) }}" onsubmit="return confirm(@js($draw->status === 'awarded'
        ? 'Delete '.$draw->label().' from '.$draw->user->name.'? It was already handed out, so it does not go back in the box. The draw is not given back.'
        : 'Delete '.$draw->label().' from '.$draw->user->name.'? It goes back in the box and the draw is not given back.'))">@csrf @method('delete')
        <button class="btn btn-small btn-outline" style="color:var(--red)" type="submit">Delete</button>
    </form>
@endif
