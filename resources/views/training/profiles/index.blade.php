@extends('layouts.app', ['title' => 'Training profiles'])

@section('band')
    <h1>Training profiles</h1>
    <p>Bundles of courses, like New Hire Orientation or EMT onboarding. Assign one to give all its courses at once, or have it given automatically to new Team Members on chosen permission profiles.</p>
@endsection

@section('content')
<div class="stack">
    <div><a class="btn" href="{{ route('training.profiles.create') }}">New training profile</a></div>
    @forelse ($profiles as $p)
        <div class="card">
            <div class="row-actions" style="justify-content:space-between">
                <h2 style="margin:0"><a href="{{ route('training.profiles.edit', $p) }}">{{ $p->name }}</a></h2>
                <div class="row-actions">
                    <a class="btn btn-small btn-outline" href="{{ route('training.assign', ['profile' => $p->id]) }}">Assign</a>
                    @if ($p->autoProfiles->isNotEmpty())
                        <form method="post" action="{{ route('training.profiles.apply', $p) }}" onsubmit="return confirm('Give {{ addslashes($p->name) }} to everyone on {{ addslashes($p->autoProfiles->map->label()->implode(', ')) }} who doesn\'t have its courses yet?')">@csrf
                            <button class="btn btn-small btn-outline" type="submit">Give to everyone on its profiles now</button>
                        </form>
                    @endif
                </div>
            </div>
            @if ($p->description)<p class="mt">{{ $p->description }}</p>@endif
            <ol class="mt">@foreach ($p->courses as $c)<li>{{ $c->title }} @unless ($c->isPublished())<span class="pill">{{ $c->statusLabel() }}</span>@endunless</li>@endforeach</ol>
            <p class="hint">
                {{ $p->due_days ? "Due {$p->due_days} days after it's given." : 'Each course uses its own days to finish.' }}
                {{ $p->autoProfiles->isNotEmpty() ? 'Given automatically to: '.$p->autoProfiles->map->label()->implode(', ').'.' : 'Only given by hand.' }}
            </p>
        </div>
    @empty
        <div class="card"><p class="empty">No training profiles yet.</p></div>
    @endforelse
</div>
@endsection
