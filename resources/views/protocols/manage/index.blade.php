@extends('layouts.app', ['title' => 'Build protocols'])

@section('band')
    <h1>Build protocols</h1>
    <p>Write and change protocols here. Nothing reaches the Protocol Book until the Medical Director approves it, and the approved version stays in the book while a change is being worked on.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <div class="protocol-list-head">
            <h2 style="margin:0">Protocols</h2>
            <div class="row-actions" style="margin:0">
                <a class="btn btn-outline" href="{{ route('protocols.imports') }}">Import from PDF or photo</a>
                <a class="btn" href="{{ route('protocols.manage.create') }}">New protocol</a>
            </div>
        </div>
        @if ($protocols->isEmpty())
            <p class="empty mt">No protocols yet. Start with New protocol.</p>
        @else
            <form method="post" action="{{ route('protocols.manage.recategorize') }}" id="bulk">@csrf
            <div class="bulk-bar mt" id="bulk-bar">
                <span id="bulk-count" class="hint">Tick protocols to move them to another category.</span>
                <select name="protocol_category_id" aria-label="New category" id="bulk-category" required disabled>
                    <option value="" selected disabled>Pick a category</option>
                    <option value="none">No category</option>
                    @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
                <button class="btn btn-small" type="submit" id="bulk-button" disabled>Move to category</button>
            </div>
            <div class="table-wrap mt"><table>
                <tr><th style="width:36px"><input type="checkbox" id="select-all" aria-label="Select all"></th><th>Protocol</th><th>Category</th><th>In the book</th><th>Being worked on</th><th>Last change</th></tr>
                @foreach ($protocols as $p)
                    @php($latest = $p->latest)
                    <tr>
                        <td><input type="checkbox" class="pick" name="protocols[]" value="{{ $p->id }}" aria-label="Select {{ $latest?->fullTitle() }}"></td>
                        <td><a href="{{ route('protocols.manage.edit', $p) }}">{{ $latest?->fullTitle() }}</a></td>
                        <td>{{ $latest?->category_name ?? '—' }}<div class="hint">{{ $latest?->agesLabel() }}</div></td>
                        <td>
                            @if ($p->retired_at)<span class="pill">Retired</span>
                            @elseif ($p->approved)<span class="pill pill-green">Version {{ $p->approved->version }}</span>
                            @else<span class="pill">Not yet</span>@endif
                        </td>
                        <td>
                            @if ($latest && in_array($latest->status, \App\Models\ProtocolVersion::OPEN, true))
                                <span @class(['pill', 'pill-teal' => $latest->status === 'submitted', 'pill-red' => $latest->status === 'returned'])>Version {{ $latest->version }}: {{ $latest->statusLabel() }}</span>
                            @else — @endif
                        </td>
                        <td>{{ $latest?->updated_at?->format('M j, Y') }}<div class="hint">{{ $latest?->editor?->name }}</div></td>
                    </tr>
                @endforeach
            </table></div>
            </form>
            <p class="hint mt">Drafts move right away. An approved protocol gets a new draft in the new category, and the book shows the new category once the Medical Director approves it. Protocols waiting for approval are left alone.</p>
            <script>
            (function () {
                var all = document.getElementById('select-all'), picks = [].slice.call(document.querySelectorAll('#bulk .pick')),
                    count = document.getElementById('bulk-count'), select = document.getElementById('bulk-category'), button = document.getElementById('bulk-button');
                function update() {
                    var n = picks.filter(function (p) { return p.checked; }).length;
                    all.checked = n > 0 && n === picks.length; all.indeterminate = n > 0 && n < picks.length;
                    select.disabled = button.disabled = n === 0;
                    count.textContent = n === 0 ? 'Tick protocols to move them to another category.' : n + (n === 1 ? ' protocol' : ' protocols') + ' selected. Move to:';
                }
                all.addEventListener('change', function () { picks.forEach(function (p) { p.checked = all.checked; }); update(); });
                picks.forEach(function (p) { p.addEventListener('change', update); });
                update();
            })();
            </script>
        @endif
    </div>

    <div class="card" style="max-width:720px">
        <h2>Categories</h2>
        <p class="hint">The chapters of the book, in this order. Approved protocols keep the category name they were approved with.</p>
        <div class="table-wrap"><table>
            <tr><th>Order</th><th>Name</th><th></th></tr>
            @foreach ($categories as $c)
                <tr>
                    <td colspan="2">
                        <form method="post" action="{{ route('protocols.categories.update', $c) }}" class="row-actions" style="margin:0;flex-wrap:nowrap">@csrf @method('put')
                            <input type="number" name="position" value="{{ $c->position }}" min="0" max="999" style="width:80px" aria-label="Order">
                            <input type="text" name="name" value="{{ $c->name }}" required maxlength="60" aria-label="Name">
                            <button class="btn btn-small btn-outline" type="submit">Save</button>
                        </form>
                    </td>
                    <td>
                        @unless (in_array($c->id, $used))
                            <form method="post" action="{{ route('protocols.categories.destroy', $c) }}" onsubmit="return confirm('Delete the {{ addslashes($c->name) }} category?')">@csrf @method('delete')
                                <button class="btn btn-small btn-outline" type="submit">Delete</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @endforeach
        </table></div>
        <form method="post" action="{{ route('protocols.categories.store') }}" class="row-actions mt">@csrf
            <input type="text" name="name" placeholder="New category, e.g. Trauma" required maxlength="60" style="max-width:320px" aria-label="New category">
            <button class="btn btn-small" type="submit">Add category</button>
        </form>
    </div>
</div>
@endsection
