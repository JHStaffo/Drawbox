<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}Draw Box · Fast Track EMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Oswald:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
    <link rel="icon" href="{{ asset('images/logo.png') }}">
</head>
<body>
@auth
    @php($u = auth()->user())
    <header class="topbar">
        <div class="topbar-inner">
            <a href="{{ route('home') }}"><img src="{{ asset('images/logo.png') }}" alt="Fast Track EMS"></a>
            <span class="app-name">Draw Box</span>
            <nav class="nav">
                <a href="{{ route('home') }}" @class(['active' => request()->routeIs('home', 'draws.*')])>My draws</a>
                @if ($u->hasRole('dispatcher'))
                    <a href="{{ route('dispatch.create') }}" @class(['active' => request()->routeIs('dispatch.*')])>Credit a call</a>
                @endif
                @if ($u->hasRole('supervisor'))
                    <a href="{{ route('supervisor.dashboard') }}" @class(['active' => request()->routeIs('supervisor.dashboard')])>Dashboard</a>
                @endif
                @if ($u->hasRole('admin'))
                    <a href="{{ route('supervisor.reports') }}" @class(['active' => request()->routeIs('supervisor.reports')])>Reports</a>
                    <a href="{{ route('supervisor.inventory') }}" @class(['active' => request()->routeIs('supervisor.inventory*')])>Inventory</a>
                    <a href="{{ route('supervisor.settings') }}" @class(['active' => request()->routeIs('supervisor.settings')])>Settings</a>
                    <a href="{{ route('admin.lockers') }}" @class(['active' => request()->routeIs('admin.lockers')])>Lockers</a>
                    <a href="{{ route('admin.users') }}" @class(['active' => request()->routeIs('admin.users*', 'admin.draws.*')])>Team Members</a>
                @endif
                <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
            </nav>
        </div>
    </header>
@endauth

@hasSection('band')
    <section class="band"><div class="band-inner">@yield('band')</div></section>
@endif

@hasSection('bare')
    @yield('bare')
@else
    <main>
        @if (session('status'))<div class="flash flash-ok">{{ session('status') }}</div>@endif
        @if (session('error'))<div class="flash flash-error">{{ session('error') }}</div>@endif
        @if ($errors->any())
            <div class="flash flash-error">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
        @endif
        @yield('content')
    </main>
@endif
@stack('scripts')
</body>
</html>
