@extends('layouts.app', ['title' => 'Enter code'])

@section('bare')
<div class="auth-wrap">
    <form class="auth-card" method="post" action="{{ url('/login/code') }}">
        @csrf
        <img src="{{ asset('images/logo.png') }}" alt="Fast Track EMS">
        <h1>Check your email</h1>
        <p class="hint" style="text-align:center">If <strong>{{ $email }}</strong> is on the roster, a 6-digit code is on its way. It expires in 10 minutes.</p>
        <div class="field">
            <label for="code">Code</label>
            <input id="code" class="code-input" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus>
            @error('code')<div class="error">{{ $message }}</div>@enderror
        </div>
        <button class="btn btn-block" type="submit">Sign in</button>
        <p class="hint mt" style="text-align:center"><a href="{{ route('login') }}">Use a different email or send a new code</a></p>
    </form>
</div>
@endsection
