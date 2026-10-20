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

        @if ($draw->status === 'pending_brand')
            @if (session('error'))<div class="flash flash-error" style="max-width:640px;margin:12px auto">{{ session('error') }}</div>@endif
            @if ($draw->user_id === auth()->id())
                @if ($brands->isEmpty())
                    <p>No brands are in stock for this value right now. A supervisor will sort it out with you.</p>
                @else
                    <p>Pick the brand you'd like.</p>
                    <form method="post" action="{{ route('draws.brand', $draw) }}" class="brand-grid">
                        @csrf
                        @foreach ($brands as $stock)
                            <button type="submit" name="stock_id" value="{{ $stock->id }}">{{ $stock->brand }}</button>
                        @endforeach
                    </form>
                @endif
            @else
                <p>Waiting for {{ $draw->user->name }} to pick a brand.</p>
            @endif
        @elseif ($draw->status === 'awarded')
            <p>Received on {{ $draw->awarded_at->format('M j, Y') }}.</p>
        @else
            <p>A supervisor will hand it to you and mark it received.</p>
            <a class="btn mt" href="{{ route('home') }}">Back to my draws</a>
        @endif
    </div>
</div>
@endsection
