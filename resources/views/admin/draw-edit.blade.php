@extends('layouts.app', ['title' => 'Edit prize'])

@section('band')
    <h1>Edit {{ $draw->user->name }}'s prize</h1>
    <p>Drawn {{ $draw->drawn_at->format('M j, Y') }}. This changes the record that's used for reports and tax totals, but it doesn't change the inventory.</p>
@endsection

@section('content')
<form class="card" method="post" action="{{ route('admin.draws.update', $draw) }}" style="max-width:560px">@csrf @method('put')
    <div class="field"><label for="prize_name">Prize</label><input id="prize_name" type="text" name="prize_name" value="{{ old('prize_name', $draw->prize_name) }}" required></div>
    @if ($draw->prize_type === 'gift_card' && ! $draw->cash_bonus)
        <div class="field"><label for="brand">Gift card brand</label><input id="brand" type="text" name="brand" value="{{ old('brand', $draw->brand) }}"></div>
    @endif
    <div class="field-row">
        <div class="field"><label for="value">Value $</label><input id="value" type="number" step="0.01" min="0" name="value" value="{{ old('value', $draw->value_cents / 100) }}" required></div>
        <div class="field"><label for="pto_hours">PTO hours</label><input id="pto_hours" type="number" step="0.25" min="0" name="pto_hours" value="{{ old('pto_hours', $draw->pto_hours + 0 ?: '') }}"></div>
    </div>
    <input type="hidden" name="taxable" value="0">
    <label class="check field"><input type="checkbox" name="taxable" value="1" @checked(old('taxable', $draw->taxable))> Taxable</label>
    <div class="row-actions">
        <button class="btn" type="submit">Save</button>
        <a class="btn btn-outline" href="{{ route('supervisor.dashboard') }}">Cancel</a>
    </div>
</form>
@endsection
