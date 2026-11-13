@extends('layouts.app', ['title' => 'My Training'])

@section('band')
    <h1>My Training</h1>
    <p>{{ $open->count() ? $open->count().' '.Str::plural('course', $open->count()).' to finish.' : 'You\'re all caught up.' }}</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <h2>To do</h2>
        @if ($open->isEmpty())
            <p class="empty">Nothing assigned right now. New courses show up here.</p>
        @else
            <div class="course-tiles">
                @foreach ($open as $a)
                    <a class="course-tile" href="{{ route('training.course', $a) }}">
                        <div class="row-actions" style="justify-content:space-between">
                            <span class="hint">{{ $a->course->category ?: 'Course' }}</span>
                            @include('training.partials.status', ['assignment' => $a])
                        </div>
                        <h3>{{ $a->course->title }}</h3>
                        <div class="hint">
                            @if ($a->due_on)Due {{ $a->due_on->format('M j, Y') }} · @endif
                            {{ $a->course->estimateLabel() }} · {{ $a->percent }}% done · {{ $a->timeLabel() }} so far
                            @if ($a->reason)<br>{{ $a->reason }}@endif
                        </div>
                        @include('training.partials.progress', ['percent' => $a->percent])
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="card" id="reference">
        <h2>Reference library</h2>
        <p class="hint">Every course you've finished stays here to look back on. Search finds words anywhere in their pages.</p>
        <form method="get" action="{{ route('training') }}#reference" class="row-actions" role="search">
            <input type="search" name="q" value="{{ $q }}" placeholder="Search your finished courses, e.g. stair chair" aria-label="Search finished courses" style="flex:1;min-width:220px">
            <button class="btn" type="submit">Search</button>
            @if ($q !== '')<a class="btn btn-outline" href="{{ route('training') }}#reference">Clear</a>@endif
        </form>

        @if ($results !== null)
            @php($terms = collect(preg_split('/\s+/', $q))->filter(fn ($t) => mb_strlen($t) >= 2))
            <h3 class="mt">{{ $results->count() }} {{ Str::plural('match', $results->count()) }} for “{{ $q }}”</h3>
            @forelse ($results as $r)
                @php($snippet = e($r['snippet']))
                @foreach ($terms as $t)@php($snippet = preg_replace('/('.preg_quote(e($t), '/').')/iu', '<mark>$1</mark>', $snippet))@endforeach
                <div class="search-hit">
                    <a href="{{ route('training.lesson', [$r['assignment'], $r['lesson']]) }}"><strong>{{ $r['lesson']->title }}</strong></a>
                    <span class="hint">· {{ $r['lesson']->course->title }} · {{ $r['lesson']->typeLabel() }}</span>
                    <div class="hint">{!! $snippet !!}</div>
                </div>
            @empty
                <p class="empty">Nothing in your finished courses matches. Try fewer or different words.</p>
            @endforelse
        @endif

        @if ($done->isEmpty())
            <p class="empty mt">Courses you finish show up here.</p>
        @else
            <div class="table-wrap mt"><table>
                <thead><tr><th>Course</th><th>Completed</th><th class="num">Your time</th><th class="num">Quiz grade</th><th></th></tr></thead>
                <tbody>
                @foreach ($done as $a)
                    <tr>
                        <td><a href="{{ route('training.course', $a) }}">{{ $a->course->title }}</a><div class="hint">{{ $a->course->lessons->count() }} {{ Str::plural('lesson', $a->course->lessons->count()) }} · {{ $a->course->estimateLabel() }}</div></td>
                        <td>{{ $a->completed_at->format('M j, Y') }}</td>
                        <td class="num">{{ $a->timeLabel() }}</td>
                        <td class="num">{{ $a->score !== null ? $a->score.'%' : '—' }}</td>
                        <td>@if ($a->replaced_at)<span class="pill">Assigned again</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</div>
@endsection
