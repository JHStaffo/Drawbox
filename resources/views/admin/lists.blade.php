@extends('layouts.app', ['title' => 'Shared lists'])

@section('band')
    <h1>Shared lists</h1>
    <p>Lists like Facilities that form questions pick from. People filling in a form can add new entries when the list allows it.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <div class="field-row" style="align-items:flex-end">
            @if ($lists->isNotEmpty())
                <form method="get" action="{{ route('admin.lists') }}" class="field">
                    <label for="list">List</label>
                    <select id="list" name="list" onchange="this.form.submit()">
                        @foreach ($lists as $l)<option value="{{ $l->id }}" @selected($current?->id === $l->id)>{{ $l->name }} ({{ $l->items_count }})</option>@endforeach
                    </select>
                </form>
            @endif
            <form method="post" action="{{ route('admin.lists.store') }}" class="field">@csrf
                <label for="new-list">New list</label>
                <div class="row-actions" style="margin:0">
                    <input id="new-list" type="text" name="name" value="{{ old('name') }}" placeholder="e.g. Destinations" required maxlength="100">
                    <button class="btn btn-small btn-outline" type="submit">Create</button>
                </div>
                @error('name')<div class="error">{{ $message }}</div>@enderror
            </form>
        </div>
    </div>

    @if ($current)
        <div class="card" style="max-width:720px">
            <h2>{{ $current->name }}</h2>
            <form method="post" action="{{ route('admin.lists.update', $current) }}">@csrf @method('put')
                <div class="field">
                    <label for="list-name">Name</label>
                    <input id="list-name" type="text" name="name" value="{{ $current->name }}" required maxlength="100">
                </div>
                <label class="check"><input type="hidden" name="allow_add" value="0"><input type="checkbox" name="allow_add" value="1" @checked($current->allow_add)> People filling in a form can add new entries</label>
                <div class="hint">When this is off, they can only pick from the list.</div>
                <div class="row-actions">
                    <button class="btn" type="submit">Save</button>
                </div>
            </form>
            <form class="mt" method="post" action="{{ route('admin.lists.destroy', $current) }}" onsubmit="return confirm('Delete the {{ $current->name }} list and all its entries?')">@csrf @method('delete')
                <button class="btn btn-small btn-outline" type="submit">Delete list</button>
            </form>
        </div>

        <form class="card" method="post" action="{{ route('admin.lists.items.store', $current) }}" style="max-width:720px">@csrf
            <h2>Add entries</h2>
            <div class="field">
                <label for="names">One per line</label>
                <textarea id="names" name="names" rows="5" required placeholder="Riverside Regional Medical Center&#10;Sentara Norfolk General">{{ old('names') }}</textarea>
                <div class="hint">Anything already on the list (ignoring capitals and extra spaces) is skipped. Paste a whole list at once.</div>
            </div>
            <button class="btn" type="submit">Add</button>
        </form>

        <div class="card">
            <h2>Entries</h2>
            @if ($current->items->isEmpty())
                <p class="empty">Nothing on the list yet.</p>
            @else
                <div class="table-wrap"><table>
                    <tr><th>Name</th><th>Added by</th><th>Status</th><th></th></tr>
                    @foreach ($current->items as $item)
                        <tr>
                            <td>
                                <form method="post" action="{{ route('admin.lists.items.update', $item) }}" class="row-actions" style="margin:0;flex-wrap:nowrap">@csrf @method('put')
                                    <input type="text" name="name" value="{{ $item->name }}" required maxlength="200" aria-label="Name">
                                    <button class="btn btn-small btn-outline" type="submit">Save</button>
                                </form>
                            </td>
                            <td>{{ $item->addedBy?->name ?? '—' }}<div class="hint">{{ $item->created_at?->format('M j, Y') }}</div></td>
                            <td>@if ($item->active)<span class="pill pill-green">Offered</span>@else<span class="pill">Hidden</span>@endif</td>
                            <td>
                                <div class="row-actions" style="margin:0">
                                    <form method="post" action="{{ route('admin.lists.items.toggle', $item) }}">@csrf
                                        <button class="btn btn-small btn-outline" type="submit">{{ $item->active ? 'Hide' : 'Offer again' }}</button>
                                    </form>
                                    <form method="post" action="{{ route('admin.lists.items.destroy', $item) }}" onsubmit="return confirm('Remove {{ addslashes($item->name) }}? Past submissions keep it.')">@csrf @method('delete')
                                        <button class="btn btn-small btn-outline" type="submit">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </table></div>
                <p class="hint mt">Renaming, hiding or removing an entry never changes past submissions. Hidden entries aren't offered on forms but still show in reports.</p>
            @endif
        </div>
    @endif
</div>
@endsection
