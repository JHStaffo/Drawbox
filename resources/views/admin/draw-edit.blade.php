@extends('layouts.app', ['title' => 'Edit prize'])

@section('band')
    <h1>Edit {{ $draw->user->name }}'s prize</h1>
    <p>Drawn {{ $draw->drawn_at->format('M j, Y') }}. Picking a different prize puts the old one back in the box and takes the new one out.</p>
@endsection

@section('content')
@php($currentBrand = old('gift_card_stock_id', $draw->gift_card_stock_id))
<form class="card" method="post" action="{{ route('admin.draws.update', $draw) }}" style="max-width:560px">@csrf @method('put')
    @if ($draw->cash_bonus)
        <p class="hint">The team member took a cash bonus instead of this prize, so nothing moves in the box.</p>
    @endif
    <div class="field">
        <label for="prize_id">Prize</label>
        <select id="prize_id" name="prize_id" required>
            @foreach ($prizes->groupBy('type') as $type => $group)
                <optgroup label="{{ $types[$type] ?? $type }}">
                    @foreach ($group as $p)
                        <option value="{{ $p['id'] }}" @selected(old('prize_id', $draw->prize_id) == $p['id'])>{{ $p['name'] }}{{ $p['value'] > 0 ? ' · '.money($p['value'] * 100) : '' }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
    </div>
    <div class="field" id="brand-field" @if ($draw->cash_bonus) data-cash-bonus @endif>
        <label for="gift_card_stock_id">Gift card brand</label>
        <select id="gift_card_stock_id" name="gift_card_stock_id"></select>
        <div class="hint" id="brand-hint"></div>
    </div>
    <div class="field-row">
        <div class="field"><label for="value">Value $</label><input id="value" type="number" step="0.01" min="0" name="value" value="{{ old('value', $draw->value_cents / 100) }}" required></div>
        <div class="field"><label for="pto_hours">PTO hours</label><input id="pto_hours" type="number" step="0.25" min="0" name="pto_hours" value="{{ old('pto_hours', $draw->pto_hours + 0 ?: '') }}"></div>
    </div>
    <input type="hidden" name="taxable" value="0">
    <label class="check field"><input id="taxable" type="checkbox" name="taxable" value="1" @checked(old('taxable', $draw->taxable))> Taxable</label>
    <div class="row-actions">
        <button class="btn" type="submit">Save</button>
        <a class="btn btn-outline" href="{{ session('draw_edit_return', route('supervisor.dashboard')) }}">Cancel</a>
    </div>
</form>

<script>
(function () {
    const prizes = @json($prizes->keyBy('id'));
    const cashBonus = {{ $draw->cash_bonus ? 'true' : 'false' }};
    const awarded = {{ $draw->status === 'awarded' ? 'true' : 'false' }};
    let brand = @json($currentBrand ? (int) $currentBrand : null);
    const prizeSelect = document.getElementById('prize_id');
    const brandField = document.getElementById('brand-field');
    const brandSelect = document.getElementById('gift_card_stock_id');
    const brandHint = document.getElementById('brand-hint');

    function showBrands(prize) {
        const show = prize.gift_card && !cashBonus;
        brandField.hidden = !show;
        brandSelect.disabled = !show;
        brandSelect.required = show && awarded;
        brandSelect.innerHTML = '';
        if (!show) return;
        if (!awarded) brandSelect.add(new Option('Let the team member pick', ''));
        prize.brands.forEach(function (b) {
            brandSelect.add(new Option(b.brand + ' (' + b.left + ' left)', b.id, false, b.id === brand));
        });
        brandHint.textContent = prize.brands.length ? '' : 'This gift card value has no brands yet. Add one on the Inventory page.';
    }

    prizeSelect.addEventListener('change', function () {
        const prize = prizes[prizeSelect.value];
        brand = null;
        document.getElementById('value').value = prize.value;
        document.getElementById('pto_hours').value = cashBonus ? '' : prize.pto_hours;
        document.getElementById('taxable').checked = cashBonus || prize.taxable;
        showBrands(prize);
    });

    showBrands(prizes[prizeSelect.value]);
})();
</script>
@endsection
