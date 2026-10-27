{{-- A prize's gift card brands or choices, with a form to add several at once. --}}
<div class="table-wrap mt"><table>
    <tr><th>{{ ucfirst($noun) }}</th><th class="num">On hand</th><th></th></tr>
    @forelse ($prize->stocks->where('active', true) as $stock)
        <tr>
            <td>{{ $stock->brand }}
                <form id="s{{ $stock->id }}" method="post" action="{{ route('supervisor.inventory.stocks.update', $stock) }}">@csrf @method('put')</form>
            </td>
            <td class="num"><input form="s{{ $stock->id }}" type="number" min="0" name="quantity" value="{{ $stock->quantity }}" style="width:70px"></td>
            <td><div class="row-actions" style="flex-wrap:nowrap">
                <button form="s{{ $stock->id }}" class="btn btn-small btn-teal" type="submit">Save</button>
                @include('supervisor.partials.hide-delete', ['toggle' => route('supervisor.inventory.stocks.toggle', $stock), 'destroy' => route('supervisor.inventory.stocks.destroy', $stock), 'name' => $prize->name.' '.$stock->brand, 'hidden' => false])
            </div></td>
        </tr>
    @empty
        <tr><td colspan="3" class="empty">No {{ $noun }}s yet.</td></tr>
    @endforelse
</table></div>
<details class="mt">
    <summary>Add {{ $noun }}s</summary>
    <form method="post" action="{{ route('supervisor.inventory.stocks.store', $prize) }}" class="mt">@csrf
        @include('supervisor.partials.add-stocks')
    </form>
</details>
