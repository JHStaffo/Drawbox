@extends('layouts.app', ['title' => 'Sign in'])

@section('bare')
<div class="auth-wrap">
    <form class="auth-card" method="post" action="{{ url('/login') }}">
        @csrf
        <img src="{{ asset('images/logo.png') }}" alt="Fast Track EMS">
        <h1>Fast Track Pulse</h1>
        <p class="hint" style="text-align:center">Enter your work email and we'll send you a sign-in code.</p>
        @if (session('status'))<div class="flash flash-ok">{{ session('status') }}</div>@endif
        <div class="field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
            @error('email')<div class="error">{{ $message }}</div>@enderror
        </div>
        <button class="btn btn-block" type="submit">Send my code</button>
    </form>
</div>
@endsection
