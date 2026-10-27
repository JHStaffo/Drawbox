@extends('layouts.app', ['title' => 'Inventory'])

@section('band')
    <h1>Inventory</h1>
    <p>Each prize shows <strong>draws left / on hand</strong>. On hand is everything in the safe, including every gift card brand. Draws left is how many can still be drawn this month: every draw uses one up, even when the winner takes a cash bonus, and only a prize actually handed over lowers what's on hand. The odds come from the draws left, and a prize at 0 of either is out of the drawing. Prizes that aren't diminishing (like candy and snack) never run out when drawn.</p>
    <p>Any prize can have choices the winner picks from, like gift card brands. Each pick uses one of that choice.</p>
    <p>There is currently a value of <strong>{{ money($boxValue) }}</strong> in the draw box.</p>
@endsection

@section('content')
@php($nonCards = $prizes->where('active', true)->reject->isGiftCard())
@php($withChoices = $nonCards->filter->hasChoices())
@php($withoutChoices = $nonCards->reject->hasChoices())
@php($cards = $prizes->where('active', true)->filter->isGiftCard())
@php($hiddenPrizes = $prizes->where('active', false))
<div class="stack">
    <div class="card card-navy row-actions" style="justify-content:space-between">
        <div>
            <h2 style="margin:0">Reset draws left</h2>
            <div style="color:#d5dde4">Sets every prize's draws left back to its monthly amount. What's on hand doesn't change.</div>
        </div>
        <div class="row-actions">
            <a class="btn btn-outline" style="color:#fff" href="{{ route('supervisor.inventory.log') }}">Change log</a>
            <form method="post" action="{{ route('supervisor.inventory.reset') }}" onsubmit="return confirm('Set every prize\'s draws left to its monthly amount?')">@csrf
                <button class="btn" type="submit">Reset available</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>Prizes</h2>
        <div class="table-wrap"><table>
            <tr><th>Prize</th><th>Type</th><th class="num">Value $</th><th class="num">Draws left</th><th class="num">Monthly</th><th class="num">On hand</th><th>Diminishing</th><th>Cash bonus OK</th><th>Taxable</th><th class="num">Odds</th><th></th></tr>
            @foreach ($nonCards as $prize)
                <tr>
                    <td>
                        <form id="p{{ $prize->id }}" method="post" action="{{ route('supervisor.inventory.prizes.update', $prize) }}">@csrf @method('put')
                            <input type="hidden" name="type" value="{{ $prize->type }}">
                        </form>
                        <input form="p{{ $prize->id }}" type="text" name="name" value="{{ $prize->name }}" style="min-width:140px">
                        @if ($prize->hasChoices())
                            <div class="hint" style="margin-top:4px"><a href="#choices-{{ $prize->id }}">{{ $prize->stocks->where('active', true)->count() }} choices</a></div>
                        @endif
                        @if ($prize->type === 'pto')
                            <div class="hint" style="margin-top:4px"><input form="p{{ $prize->id }}" type="number" step="0.25" name="pto_hours" value="{{ $prize->pto_hours + 0 }}" style="width:80px"> hours</div>
                        @endif
                    </td>
                    <td><span class="pill">{{ $types[$prize->type] }}</span></td>
                    <td class="num"><input form="p{{ $prize->id }}" type="number" step="0.01" min="0" name="value" value="{{ $prize->value_cents / 100 }}" style="width:90px"></td>
                    @if ($prize->diminishes())
                        <td class="num"><input form="p{{ $prize->id }}" type="number" min="0" name="available" value="{{ $prize->available }}" style="width:80px"></td>
                        <td class="num"><input form="p{{ $prize->id }}" type="number" min="0" name="monthly_quantity" value="{{ $prize->monthly_quantity }}" style="width:80px" title="Reset sets draws left to this"></td>
                    @else
                        <td class="num hint" colspan="2" title="Drawing it never uses one up">Never runs out</td>
                    @endif
                    @if ($prize->countsFromChoices())
                        <td class="num" title="The total of its choices, less any waiting on a pick">{{ $odds[$prize->id]['on_hand'] ?? 0 }}</td>
                    @else
                        <td class="num"><input form="p{{ $prize->id }}" type="number" min="0" name="quantity" value="{{ $prize->quantity }}" style="width:80px" title="{{ $prize->diminishes() ? '' : 'Sets the odds only' }}"></td>
                    @endif
                    <td><input form="p{{ $prize->id }}" type="hidden" name="diminishing" value="0"><input form="p{{ $prize->id }}" type="checkbox" name="diminishing" value="1" @checked($prize->diminishing)></td>
                    <td><input form="p{{ $prize->id }}" type="hidden" name="cash_bonus_allowed" value="0"><input form="p{{ $prize->id }}" type="checkbox" name="cash_bonus_allowed" value="1" @checked($prize->cash_bonus_allowed)></td>
                    <td><input form="p{{ $prize->id }}" type="hidden" name="taxable" value="0"><input form="p{{ $prize->id }}" type="checkbox" name="taxable" value="1" @checked($prize->taxable)></td>
                    <td class="num">{{ isset($odds[$prize->id]) ? number_format($odds[$prize->id]['odds'] * 100, 1).'%' : '—' }}</td>
                    <td><div class="row-actions" style="flex-wrap:nowrap">
                        <button form="p{{ $prize->id }}" class="btn btn-small btn-teal" type="submit">Save</button>
                        @include('supervisor.partials.hide-delete', ['toggle' => route('supervisor.inventory.prizes.toggle', $prize), 'destroy' => route('supervisor.inventory.prizes.destroy', $prize), 'name' => $prize->name, 'hidden' => false])
                    </div></td>
                </tr>
            @endforeach
        </table></div>
    </div>

    <h2 class="mt">Prize choices</h2>
    <div class="grid grid-2">
        @foreach ($withChoices as $prize)
            <div class="card" id="choices-{{ $prize->id }}">
                <div class="row-actions" style="justify-content:space-between">
                    <h3 style="margin:0">{{ $prize->name }}</h3>
                    @if ($prize->diminishes())
                        <span class="pill pill-teal">{{ $odds[$prize->id]['left'] ?? 0 }}/{{ $odds[$prize->id]['on_hand'] ?? 0 }} left</span>
                    @else
                        <span class="pill pill-teal">{{ $prize->stocks->where('active', true)->sum('quantity') }} on hand</span>
                    @endif
                </div>
                <form id="c{{ $prize->id }}" method="post" action="{{ route('supervisor.inventory.counts.update', $prize) }}">@csrf @method('put')</form>
                @include('supervisor.partials.stock-table', ['noun' => 'choice', 'placeholder' => 'e.g. Snickers'])
            </div>
        @endforeach
        @if ($withoutChoices->isNotEmpty())
            <div class="card">
                <h3 style="margin:0">Add choices to a prize</h3>
                <p class="hint">For a prize like Candy, list each kind you have so the winner can pick one and you can track how many of each are left.</p>
                <form method="post" action="{{ route('supervisor.inventory.stocks.store', $withoutChoices->first()) }}" class="choice-prize-form">@csrf
                    <div class="field"><label>Prize</label>
                        <select class="choice-prize">
                            @foreach ($withoutChoices as $prize)<option value="{{ route('supervisor.inventory.stocks.store', $prize) }}">{{ $prize->name }}</option>@endforeach
                        </select>
                    </div>
                    @include('supervisor.partials.add-stocks', ['noun' => 'choice', 'placeholder' => 'e.g. Snickers'])
                </form>
            </div>
        @endif
    </div>

    <h2 class="mt">Gift cards</h2>
    <div class="grid grid-2">
        @foreach ($cards as $prize)
            <div class="card">
                <div class="row-actions" style="justify-content:space-between">
                    <h3 style="margin:0">{{ $prize->name }}</h3>
                    <span class="pill pill-teal">{{ $odds[$prize->id]['left'] ?? 0 }}/{{ $odds[$prize->id]['on_hand'] ?? 0 }} left · {{ isset($odds[$prize->id]) ? number_format($odds[$prize->id]['odds'] * 100, 1) : '0.0' }}%</span>
                </div>
                {{-- One Save covers these and every brand's count below. --}}
                <form id="c{{ $prize->id }}" method="post" action="{{ route('supervisor.inventory.counts.update', $prize) }}" class="row-actions mt">@csrf @method('put')
                    <label class="hint" for="available-{{ $prize->id }}">Draws left:</label>
                    <input id="available-{{ $prize->id }}" type="number" min="0" name="available" value="{{ $prize->available }}" style="width:70px">
                    <label class="hint" for="monthly-{{ $prize->id }}">Monthly:</label>
                    <input id="monthly-{{ $prize->id }}" type="number" min="0" name="monthly_quantity" value="{{ $prize->monthly_quantity }}" style="width:70px" title="Reset sets draws left to this">
                </form>
                @include('supervisor.partials.stock-table', ['noun' => 'brand', 'placeholder' => 'e.g. Amazon', 'prizeActions' => true])
                <details class="mt">
                    <summary>Edit this value</summary>
                    <form method="post" action="{{ route('supervisor.inventory.prizes.update', $prize) }}" class="mt">@csrf @method('put')
                        <input type="hidden" name="type" value="gift_card">
                        <div class="field-row">
                            <div class="field"><label>Name</label><input type="text" name="name" value="{{ $prize->name }}" required></div>
                            <div class="field"><label>Value $</label><input type="number" step="0.01" min="0" name="value" value="{{ $prize->value_cents / 100 }}" required></div>
                        </div>
                        <input type="hidden" name="cash_bonus_allowed" value="0">
                        <label class="check"><input type="checkbox" name="cash_bonus_allowed" value="1" @checked($prize->cash_bonus_allowed)> Allow taxable cash bonus instead</label>
                        <input type="hidden" name="taxable" value="0">
                        <label class="check"><input type="checkbox" name="taxable" value="1" @checked($prize->taxable)> Taxable</label>
                        <button class="btn btn-small btn-teal mt" type="submit">Save</button>
                    </form>
                </details>
            </div>
        @endforeach
    </div>

    <div class="card">
        <details @if ($hiddenPrizes->isNotEmpty() || $hiddenStocks->isNotEmpty()) open @endif>
            <summary>Hidden prizes and gift cards ({{ $hiddenPrizes->count() + $hiddenStocks->count() }})</summary>
            <p class="hint mt">Hidden items can't be drawn or picked, and reset skips them. Unhide one to put it back in the box with its counts unchanged.</p>
            @if ($hiddenPrizes->isEmpty() && $hiddenStocks->isEmpty())
                <p class="empty">Nothing is hidden.</p>
            @else
                <div class="table-wrap"><table>
                    <tr><th>Item</th><th class="num">On hand</th><th></th></tr>
                    @foreach ($hiddenPrizes as $prize)
                        <tr>
                            <td>{{ $prize->name }} <span class="pill">{{ $types[$prize->type] }}</span></td>
                            <td class="num">{{ $prize->countsFromChoices() ? $prize->stocks->sum('quantity') : $prize->quantity }}</td>
                            <td><div class="row-actions" style="justify-content:flex-end">@include('supervisor.partials.hide-delete', ['toggle' => route('supervisor.inventory.prizes.toggle', $prize), 'destroy' => route('supervisor.inventory.prizes.destroy', $prize), 'name' => $prize->name, 'hidden' => true])</div></td>
                        </tr>
                    @endforeach
                    @foreach ($hiddenStocks as $stock)
                        <tr>
                            <td>{{ $stock->prize->name }} · {{ $stock->brand }} <span class="pill">{{ $stock->prize->isGiftCard() ? 'Gift card brand' : 'Prize choice' }}</span></td>
                            <td class="num">{{ $stock->quantity }}</td>
                            <td><div class="row-actions" style="justify-content:flex-end">@include('supervisor.partials.hide-delete', ['toggle' => route('supervisor.inventory.stocks.toggle', $stock), 'destroy' => route('supervisor.inventory.stocks.destroy', $stock), 'name' => $stock->prize->name.' '.$stock->brand, 'hidden' => true])</div></td>
                        </tr>
                    @endforeach
                </table></div>
            @endif
        </details>
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
                <div class="field"><label>On hand</label><input type="number" min="0" name="quantity" value="0"></div>
                <div class="field"><label>Monthly draws</label><input type="number" min="0" name="monthly_quantity" placeholder="Same as on hand"></div>
            </div>
            <input type="hidden" name="diminishing" value="0">
            <label class="check"><input type="checkbox" name="diminishing" value="1" checked> Diminishing (drawing it uses one up)</label>
            <input type="hidden" name="cash_bonus_allowed" value="0">
            <label class="check"><input type="checkbox" name="cash_bonus_allowed" value="1"> Allow taxable cash bonus instead</label>
            <input type="hidden" name="taxable" value="0">
            <label class="check"><input type="checkbox" name="taxable" value="1" checked> Taxable</label>
            <p class="hint">For a new gift card value, choose "Gift card" and then add brands to it. To let winners pick from several kinds of a prize, add it and then add choices to it under Prize choices.</p>
            <button class="btn" type="submit">Add prize</button>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    // "+ Another row" copies the last blank row with the next index.
    document.querySelectorAll('.add-rows').forEach(function (box) {
        const button = box.querySelector('.add-row-btn');
        button.hidden = false;
        button.addEventListener('click', function () {
            const rows = box.querySelectorAll('.add-row');
            const last = rows[rows.length - 1];
            const copy = last.cloneNode(true);
            copy.querySelectorAll('input').forEach(function (input) {
                input.name = input.name.replace(/items\[\d+\]/, 'items[' + rows.length + ']');
                input.value = input.type === 'number' ? '0' : '';
                input.placeholder = '';
            });
            last.after(copy);
            copy.querySelector('input').focus();
        });
    });

    // The "Add choices to a prize" form posts to whichever prize is picked.
    document.querySelectorAll('.choice-prize-form').forEach(function (form) {
        const select = form.querySelector('.choice-prize');
        select.addEventListener('change', function () { form.action = select.value; });
        form.action = select.value;
    });
})();
</script>
@endpush
