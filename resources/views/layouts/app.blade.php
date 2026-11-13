<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}Fast Track Pulse · Fast Track EMS</title>
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
            <span class="app-name">Fast Track Pulse</span>
            <nav class="nav">
                <a href="{{ route('home') }}" @class(['active' => request()->routeIs('home')])>Home</a>
                <details class="nav-menu">
                    <summary @class(['active' => request()->routeIs('drawbox.home', 'draws.*', 'supervisor.dashboard', 'supervisor.dispense', 'supervisor.reports*', 'supervisor.inventory*', 'admin.lockers', 'admin.draws.*')])>Draw Box</summary>
                    <div class="nav-drop">
                        <a href="{{ route('drawbox.home') }}" @class(['active' => request()->routeIs('drawbox.home', 'draws.*')])>Draw Box Home</a>
                        @if ($u->hasPermission('dashboard'))<a href="{{ route('supervisor.dashboard') }}" @class(['active' => request()->routeIs('supervisor.dashboard', 'supervisor.dispense')])>Dashboard</a>@endif
                        @if ($u->hasPermission('reports'))<a href="{{ route('supervisor.reports') }}" @class(['active' => request()->routeIs('supervisor.reports*')])>Reports</a>@endif
                        @if ($u->hasPermission('inventory'))<a href="{{ route('supervisor.inventory') }}" @class(['active' => request()->routeIs('supervisor.inventory*')])>Inventory</a>@endif
                        @if ($u->hasPermission('lockers'))<a href="{{ route('admin.lockers') }}" @class(['active' => request()->routeIs('admin.lockers')])>Lockers</a>@endif
                    </div>
                </details>
                @if ($u->hasAnyPermission('credit_calls', 'scope_check', 'forms', 'form_reports'))
                    <details class="nav-menu">
                        <summary @class(['active' => request()->routeIs('dispatch.*', 'scope', 'forms*')])>Dispatch</summary>
                        <div class="nav-drop">
                            @if ($u->hasPermission('credit_calls'))<a href="{{ route('dispatch.create') }}" @class(['active' => request()->routeIs('dispatch.*')])>Credit a call</a>@endif
                            @if ($u->hasPermission('scope_check'))<a href="{{ route('scope') }}" @class(['active' => request()->routeIs('scope')])>Scope Check</a>@endif
                            @if ($u->hasPermission('forms'))<a href="{{ route('forms') }}" @class(['active' => request()->routeIs('forms', 'forms.show')])>Forms</a>@endif
                            @if ($u->hasPermission('form_reports'))<a href="{{ route('forms.reports') }}" @class(['active' => request()->routeIs('forms.reports*')])>Form reports</a>@endif
                        </div>
                    </details>
                @endif
                @php($wellnessOn = \App\Wellness\Wellness::enabled())
                @if (($wellnessOn && $u->hasAnyPermission('wellness', 'wellness_admin')) || $u->hasPermission('wellness_settings'))
                    <details class="nav-menu">
                        <summary @class(['active' => request()->routeIs('wellness*')])>Wellness</summary>
                        <div class="nav-drop">
                            @if ($wellnessOn && $u->hasPermission('wellness'))
                                <a href="{{ route('wellness') }}" @class(['active' => request()->routeIs('wellness')])>My Wellness</a>
                                <a href="{{ route('wellness.log') }}" @class(['active' => request()->routeIs('wellness.log')])>Log a day</a>
                                <a href="{{ route('wellness.leaderboard') }}" @class(['active' => request()->routeIs('wellness.leaderboard')])>Leaderboard</a>
                                <a href="{{ route('wellness.connect') }}" @class(['active' => request()->routeIs('wellness.connect')])>Devices</a>
                                <a href="{{ route('wellness.rules') }}" @class(['active' => request()->routeIs('wellness.rules')])>Rules and privacy</a>
                            @endif
                            @if ($wellnessOn && $u->hasPermission('wellness_admin'))
                                <a href="{{ route('wellness.admin.participants') }}" @class(['active' => request()->routeIs('wellness.admin.participants')])>Participants</a>
                                <a href="{{ route('wellness.admin.rules') }}" @class(['active' => request()->routeIs('wellness.admin.rules')])>Point rules</a>
                            @endif
                            @if ($u->hasPermission('wellness_settings'))
                                <a href="{{ route('wellness.admin.settings') }}" @class(['active' => request()->routeIs('wellness.admin.settings')])>Wellness settings</a>
                            @endif
                        </div>
                    </details>
                @endif
                @if ($u->hasAnyPermission('team_members', 'settings', 'audit_log', 'profiles', 'manage_forms'))
                    <details class="nav-menu">
                        <summary @class(['active' => request()->routeIs('admin.users*', 'supervisor.settings', 'admin.audit', 'admin.profiles*', 'admin.forms*', 'admin.lists*', 'admin.slack*')])>Admin</summary>
                        <div class="nav-drop">
                            @if ($u->hasPermission('team_members'))<a href="{{ route('admin.users') }}" @class(['active' => request()->routeIs('admin.users*')])>Team Members</a>@endif
                            @if ($u->hasPermission('profiles'))<a href="{{ route('admin.profiles') }}" @class(['active' => request()->routeIs('admin.profiles*')])>Permission Profiles</a>@endif
                            @if ($u->hasPermission('settings'))<a href="{{ route('supervisor.settings') }}" @class(['active' => request()->routeIs('supervisor.settings')])>Settings</a>@endif
                            @if ($u->hasPermission('manage_forms'))<a href="{{ route('admin.forms') }}" @class(['active' => request()->routeIs('admin.forms*')])>Forms</a>@endif
                            @if ($u->hasPermission('manage_forms'))<a href="{{ route('admin.lists') }}" @class(['active' => request()->routeIs('admin.lists*')])>Shared lists</a>@endif
                            @if ($u->hasPermission('manage_forms'))<a href="{{ route('admin.slack') }}" @class(['active' => request()->routeIs('admin.slack*')])>Slack</a>@endif
                            @if ($u->hasPermission('audit_log'))<a href="{{ route('admin.audit') }}" @class(['active' => request()->routeIs('admin.audit')])>Audit log</a>@endif
                        </div>
                    </details>
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
<script>
    // One nav menu open at a time; a click anywhere else closes it.
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.nav-menu[open]').forEach(function (m) { if (!m.contains(e.target)) m.removeAttribute('open'); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('.nav-menu[open]').forEach(function (m) { m.removeAttribute('open'); });
    });
</script>
@stack('scripts')
</body>
</html>
