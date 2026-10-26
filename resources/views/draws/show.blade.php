@extends('layouts.app', ['title' => 'Your prize'])

@section('band')
<div class="reveal">
    <div @class(['envelope', 'animate' => $reveal])>
        <div class="body"></div>
        <div class="card-inner"><span class="prize-text">{{ $draw->prize_name }}</span></div>
        <div class="front"></div>
        <div class="flap"></div>
    </div>
    <div @class(['prize-text' => $reveal])>
        <div class="stat-label" style="color:#d5dde4">{{ $draw->user->name }} won</div>
        <p class="prize-name">{{ $draw->label() }}</p>
        @if (session('error') && $draw->status !== 'pending_brand')<div class="flash flash-error" style="max-width:640px;margin:12px auto">{{ session('error') }}</div>@endif

        @if ($draw->status === 'pending_brand')
            @if (session('error'))<div class="flash flash-error" style="max-width:640px;margin:12px auto">{{ session('error') }}</div>@endif
            @if ($draw->user_id === auth()->id())
                @if ($brands->isEmpty())
                    <p>None are in stock right now. A supervisor will sort it out with you.</p>
                @else
                    <p>Pick the {{ $draw->choiceNoun() }} you'd like.</p>
                    <form method="post" action="{{ route('draws.brand', $draw) }}" class="brand-grid">
                        @csrf
                        @foreach ($brands as $stock)
                            <button type="submit" name="stock_id" value="{{ $stock->id }}">{{ $stock->brand }}</button>
                        @endforeach
                    </form>
                @endif
            @else
                <p>Waiting for {{ $draw->user->name }} to pick a {{ $draw->choiceNoun() }}.</p>
            @endif
        @elseif ($draw->status === 'awarded')
            <p>{{ $draw->cash_bonus ? 'Paid' : ($draw->pto_hours !== null ? 'Added to your PTO bank' : 'Received') }} on {{ $draw->awarded_at->format('M j, Y') }}.</p>
        @elseif ($draw->cash_bonus)
            <p>It will be added to your pay as a taxable bonus. A supervisor will mark it paid.</p>
            <a class="btn mt" href="{{ route('home') }}">Back to my draws</a>
        @elseif ($draw->pto_hours !== null)
            <p>{{ $draw->pto_hours + 0 }} Hours of PTO will be added to your PTO bank.</p>
            <a class="btn mt" href="{{ route('home') }}">Back to my draws</a>
        @else
            <p>A supervisor will hand it to you and mark it received.</p>
            <a class="btn mt" href="{{ route('home') }}">Back to my draws</a>
        @endif

        @if ($draw->user_id === auth()->id() && $draw->canTakeCashBonus())
            <form method="post" action="{{ route('draws.cash-bonus', $draw) }}" class="mt" onsubmit="return confirm('Take the taxable cash bonus instead of the prize? This can\'t be undone.')">@csrf
                <p style="color:#d5dde4;margin-bottom:8px">Or, instead of the prize:</p>
                <button class="btn btn-outline" style="color:#fff" type="submit">Issue as taxable cash bonus ({{ money($draw->value_cents) }})</button>
            </form>
        @endif
    </div>
</div>
@endsection
