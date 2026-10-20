@extends('layouts.app', ['title' => 'Inventory'])

@section('band')
    <h1>Inventory and restocking</h1>
    <p>The odds are the envelopes left for each prize out of all envelopes in the box. Prizes that aren't diminishing (like candy and snack) never run out when drawn.</p>
    <p>There is currently a value of <strong>{{ money($boxValue) }}</strong> in the draw box.</p>
@endsection

@section('content')
@php($nonCards = $prizes->reject->isGiftCard())
@php($cards = $prizes->filter->isGiftCard())
<div class="stack">
    <div class="card card-navy row-actions" style="justify-content:space-between">
        <div>
            <h2 style="margin:0">Monthly restock</h2>
            <div style="color:#d5dde4">Tops every prize and gift card brand up to its monthly amount. Nothing above that amount is removed.</div>
        </div>
        <div class="row-actions">
            <a class="btn btn-outline" style="color:#fff" href="{{ route('supervisor.inventory.log') }}">Change log</a>
            <form method="post" action="{{ route('supervisor.inventory.restock') }}" onsubmit="return confirm('Restock everything to its monthly amount?')">@csrf
                <button class="btn" type="submit">Restock now</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>Prizes</h2>
        <div class="table-wrap"><table>
            <tr><th>Prize</th><th>Type</th><th class="num">Value $</th><th class="num">In box</th><th class="num">Monthly</th><th>Diminishing</th><th>Taxable</th><th>Active</th><th class="num">Odds</th><th></th></tr>
            @foreach ($nonCards as $prize)
                <tr>
                    <td>
                        <form id="p{{ $prize->id }}" method="post" action="{{ route('supervisor.inventory.prizes.update', $prize) }}">@csrf @method('put')
                            <input type="hidden" name="type" value="{{ $prize->type }}">
                        </form>
                        <input form="p{{ $prize->id }}" type="text" name="name" value="{{ $prize->name }}" style="min-width:140px">
                        @if ($prize->type === 'pto')
                            <div class="hint" style="margin-top:4px"><input form="p{{ $prize->id }}" type="number" step="0.25" name="pto_hours" value="{{ $prize->pto_hours + 0 }}" style="width:80px"> hours</div>
                        @endif
                    </td>
                    <td><span class="pill">{{ $types[$prize->type] }}</span></td>
                    <td class="num"><input form="p{{ $prize->id }}" type="number" step="0.01" min="0" name="value" value="{{ $prize->value_cents / 100 }}" style="width:90px"></td>
                    <td class="num"><input form="p{{ $prize->id }}" type="number" min="0" name="quantity" value="{{ $prize->quantity }}" style="width:80px"></td>
                    <td class="num"><input form="p{{ $prize->id }}" type="number" min="0" name="monthly_quantity" value="{{ $prize->monthly_quantity }}" style="width:80px" @disabled(! $prize->diminishing) title="{{ $prize->diminishing ? '' : 'Not restocked because drawing it never uses one up' }}"></td>
                    <td><input form="p{{ $prize->id }}" type="hidden" name="diminishing" value="0"><input form="p{{ $prize->id }}" type="checkbox" name="diminishing" value="1" @checked($prize->diminishing)></td>
                    <td><input form="p{{ $prize->id }}" type="hidden" name="taxable" value="0"><input form="p{{ $prize->id }}" type="checkbox" name="taxable" value="1" @checked($prize->taxable)></td>
                    <td><input form="p{{ $prize->id }}" type="hidden" name="active" value="0"><input form="p{{ $prize->id }}" type="checkbox" name="active" value="1" @checked($prize->active)></td>
                    <td class="num">{{ isset($odds[$prize->id]) ? number_format($odds[$prize->id]['odds'] * 100, 1).'%' : '—' }}</td>
                    <td><button form="p{{ $prize->id }}" class="btn btn-small btn-teal" type="submit">Save</button></td>
                </tr>
            @endforeach
        </table></div>
    </div>

    <h2 class="mt">Gift cards</h2>
    <div class="grid grid-2">
        @foreach ($cards as $prize)
            <div class="card">
                <div class="row-actions" style="justify-content:space-between">
                    <h3 style="margin:0">{{ $prize->name }}</h3>
                    <span class="pill pill-teal">{{ $odds[$prize->id]['count'] ?? 0 }} left · {{ isset($odds[$prize->id]) ? number_format($odds[$prize->id]['odds'] * 100, 1) : '0.0' }}%</span>
                </div>
                <div class="table-wrap mt"><table>
                    <tr><th>Brand</th><th class="num">In box</th><th class="num">Monthly</th><th></th></tr>
                    @forelse ($prize->stocks as $stock)
                        <tr>
                            <td>{{ $stock->brand }}
                                <form id="s{{ $stock->id }}" method="post" action="{{ route('supervisor.inventory.stocks.update', $stock) }}">@csrf @method('put')</form>
                            </td>
                            <td class="num"><input form="s{{ $stock->id }}" type="number" min="0" name="quantity" value="{{ $stock->quantity }}" style="width:70px"></td>
                            <td class="num"><input form="s{{ $stock->id }}" type="number" min="0" name="monthly_quantity" value="{{ $stock->monthly_quantity }}" style="width:70px"></td>
                            <td><button form="s{{ $stock->id }}" class="btn btn-small btn-teal" type="submit">Save</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty">No brands yet.</td></tr>
                    @endforelse
                </table></div>
                <details class="mt">
                    <summary>Add a brand</summary>
                    <form method="post" action="{{ route('supervisor.inventory.stocks.store', $prize) }}" class="mt">@csrf
                        <div class="field-row">
                            <div class="field"><label>Brand</label><input type="text" name="brand" required placeholder="e.g. Amazon"></div>
                            <div class="field"><label>In box</label><input type="number" min="0" name="quantity" value="0" required></div>
                            <div class="field"><label>Monthly</label><input type="number" min="0" name="monthly_quantity" value="0" required></div>
                        </div>
                        <button class="btn btn-small" type="submit">Add brand</button>
                    </form>
                </details>
                <details class="mt">
                    <summary>Edit this value</summary>
                    <form method="post" action="{{ route('supervisor.inventory.prizes.update', $prize) }}" class="mt">@csrf @method('put')
                        <input type="hidden" name="type" value="gift_card">
                        <div class="field-row">
                            <div class="field"><label>Name</label><input type="text" name="name" value="{{ $prize->name }}" required></div>
                            <div class="field"><label>Value $</label><input type="number" step="0.01" min="0" name="value" value="{{ $prize->value_cents / 100 }}" required></div>
                        </div>
                        <input type="hidden" name="taxable" value="0">
                        <label class="check"><input type="checkbox" name="taxable" value="1" @checked($prize->taxable)> Taxable</label>
                        <input type="hidden" name="active" value="0">
                        <label class="check"><input type="checkbox" name="active" value="1" @checked($prize->active)> Active</label>
                        <button class="btn btn-small btn-teal mt" type="submit">Save</button>
                    </form>
                </details>
            </div>
        @endforeach
    </div>

    <div class="card">
        <h2>Add a prize</h2>
        <form method="post" action="{{ route('supervisor.inventory.prizes.store') }}">@csrf
            <div class="field-row">
                <div class="field"><label>Name</label><input type="text" name="name" required placeholder="e.g. Pen light"></div>
                <div class="field"><label>Type</label>
                    <select name="type">@foreach ($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                </div>
                <div class="field"><label>Value $</label><input type="number" step="0.01" min="0" name="value" value="0" required></div>
                <div class="field"><label>PTO hours</label><input type="number" step="0.25" min="0" name="pto_hours" placeholder="PTO only"></div>
                <div class="field"><label>In box</label><input type="number" min="0" name="quantity" value="0"></div>
                <div class="field"><label>Monthly</label><input type="number" min="0" name="monthly_quantity" value="0"></div>
            </div>
            <input type="hidden" name="diminishing" value="0">
            <label class="check"><input type="checkbox" name="diminishing" value="1" checked> Diminishing (drawing it uses one up)</label>
            <input type="hidden" name="taxable" value="0">
            <label class="check"><input type="checkbox" name="taxable" value="1" checked> Taxable</label>
            <p class="hint">For a new gift card value, choose "Gift card" and then add brands to it.</p>
            <button class="btn" type="submit">Add prize</button>
        </form>
    </div>
</div>
@endsection
