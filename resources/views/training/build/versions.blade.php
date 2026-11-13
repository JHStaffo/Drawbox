@extends('layouts.app', ['title' => $course->title.' versions'])

@section('band')
    <p style="margin:0 0 .3em"><a style="color:#fff" href="{{ route('training.courses.edit', $course) }}">{{ $course->title }}</a></p>
    <h1>Version history</h1>
    <p>{{ $course->isVersioned() ? 'Now on version '.$course->version : 'Not published yet' }} · {{ $finished->count() }} {{ Str::plural('Team Member', $finished->count()) }} finished it</p>
@endsection

@section('content')
<div class="stack">
    @if ($course->isVersioned())
        @php($latestViews = ($views[$course->version] ?? collect())->keyBy('user_id'))
        <div class="card">
            <h2>Who has looked at version {{ $course->version }}</h2>
            <p class="hint">Team Members who finished the course, and when they first and last opened this version (the course or any lesson in it).</p>
            @if ($finished->isEmpty())
                <p class="empty">Nobody has finished this course yet.</p>
            @else
                <div class="table-wrap"><table>
                    <thead><tr><th>Team Member</th><th>Finished</th><th>Version finished</th><th>First reviewed v{{ $course->version }}</th><th>Last viewed v{{ $course->version }}</th></tr></thead>
                    <tbody>
                        @foreach ($finished->sortBy(fn ($a) => [$latestViews->has($a->user_id) ? 1 : 0, mb_strtolower($a->user->name)]) as $a)
                            @php($v = $latestViews[$a->user_id] ?? null)
                            <tr>
                                <td><a href="{{ route('training.person', $a->user) }}">{{ $a->user->name }}</a></td>
                                <td>{{ $a->completed_at->format('M j, Y') }}</td>
                                <td>{{ $a->completed_version ? 'v'.$a->completed_version : '' }}</td>
                                <td>@if ($v){{ $v->first_viewed_at->format('M j, Y g:i A') }}@else<span class="pill pill-amber">Not yet</span>@endif</td>
                                <td>{{ $v?->last_viewed_at->format('M j, Y g:i A') }}@if ($v && $v->views > 1) <span class="hint">({{ $v->views }} visits)</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>
    @endif

    <div class="card">
        <h2>Versions</h2>
        @forelse ($versions as $ver)
            @php($vv = $views[$ver->number] ?? collect())
            <details class="quiz-q" @if ($loop->first) open @endif>
                <summary><strong>Version {{ $ver->number }}</strong> · {{ $ver->published_at->format('M j, Y g:i A') }}@if ($ver->publisher) · {{ $ver->publisher->name }}@endif · opened by {{ $vv->count() }} {{ Str::plural('Team Member', $vv->count()) }}</summary>
                @if ($ver->note)<p class="mt">{{ $ver->note }}</p>@endif
                @if ($ver->changes)
                    <ul>@foreach ($ver->changes as $c)<li><span class="pill">{{ \App\Training\Models\CourseVersion::KINDS[$c['kind']] ?? $c['kind'] }}</span> {{ $c['title'] }}</li>@endforeach</ul>
                @endif
                @if ($vv->isNotEmpty())
                    <div class="table-wrap"><table>
                        <thead><tr><th>Team Member</th><th>First reviewed</th><th>Last viewed</th><th>Visits</th></tr></thead>
                        <tbody>@foreach ($vv->sortBy('first_viewed_at') as $view)<tr><td>{{ $view->user?->name }}</td><td>{{ $view->first_viewed_at->format('M j, Y g:i A') }}</td><td>{{ $view->last_viewed_at->format('M j, Y g:i A') }}</td><td>{{ $view->views }}</td></tr>@endforeach</tbody>
                    </table></div>
                @endif
            </details>
        @empty
            <p class="empty">No versions yet. The course becomes version 1 when it's first published.</p>
        @endforelse
    </div>
</div>
@endsection
