{{-- A prize's gift card brands or choices, with one Save for all their counts and a form to add several at once. --}}
<div class="table-wrap mt"><table class="stock-table">
    <tr><th>{{ ucfirst($noun) }}</th><th class="num">On hand</th><th></th></tr>
    @forelse ($prize->stocks->where('active', true) as $stock)
        <tr>
            <td>{{ $stock->brand }}</td>
            <td class="num"><input form="c{{ $prize->id }}" type="number" min="0" name="stocks[{{ $stock->id }}]" value="{{ $stock->quantity }}" style="width:56px" aria-label="{{ $stock->brand }} on hand" required></td>
            <td><div class="row-actions" style="flex-wrap:nowrap;justify-content:flex-end">
                @include('supervisor.partials.hide-delete', ['toggle' => route('supervisor.inventory.stocks.toggle', $stock), 'destroy' => route('supervisor.inventory.stocks.destroy', $stock), 'name' => $prize->name.' '.$stock->brand, 'hidden' => false])
            </div></td>
        </tr>
    @empty
        <tr><td colspan="3" class="empty">No {{ $noun }}s yet.</td></tr>
    @endforelse
</table></div>
<div class="row-actions mt">
    <button form="c{{ $prize->id }}" class="btn btn-small btn-teal" type="submit">Save</button>
    @if ($prizeActions ?? false)
        @include('supervisor.partials.hide-delete', ['toggle' => route('supervisor.inventory.prizes.toggle', $prize), 'destroy' => route('supervisor.inventory.prizes.destroy', $prize), 'name' => $prize->name, 'hidden' => false, 'what' => 'prize'])
    @endif
</div>
<details class="mt">
    <summary>Add {{ $noun }}s</summary>
    <form method="post" action="{{ route('supervisor.inventory.stocks.store', $prize) }}" class="mt">@csrf
        @include('supervisor.partials.add-stocks')
    </form>
</details>
