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
            <a class="btn" href="{{ route('protocols.manage.create') }}">New protocol</a>
        </div>
        @if ($protocols->isEmpty())
            <p class="empty mt">No protocols yet. Start with New protocol.</p>
        @else
            <div class="table-wrap mt"><table>
                <tr><th>Protocol</th><th>Category</th><th>In the book</th><th>Being worked on</th><th>Last change</th></tr>
                @foreach ($protocols as $p)
                    @php($latest = $p->latest)
                    <tr>
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
