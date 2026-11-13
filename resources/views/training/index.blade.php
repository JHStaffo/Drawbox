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
                            {{ $a->percent }}% done · {{ $a->timeLabel() }}
                            @if ($a->reason)<br>{{ $a->reason }}@endif
                        </div>
                        @include('training.partials.progress', ['percent' => $a->percent])
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @if ($done->isNotEmpty())
        <div class="card">
            <h2>Completed</h2>
            <div class="table-wrap"><table>
                <thead><tr><th>Course</th><th>Completed</th><th class="num">Time</th><th class="num">Quiz grade</th><th></th></tr></thead>
                <tbody>
                @foreach ($done as $a)
                    <tr>
                        <td><a href="{{ route('training.course', $a) }}">{{ $a->course->title }}</a>@if ($a->replaced_at)<div class="hint">Assigned again {{ $a->replaced_at->format('M j, Y') }}</div>@endif</td>
                        <td>{{ $a->completed_at->format('M j, Y') }}</td>
                        <td class="num">{{ $a->timeLabel() }}</td>
                        <td class="num">{{ $a->score !== null ? $a->score.'%' : '—' }}</td>
                        <td>@include('training.partials.status', ['assignment' => $a])</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
    @endif
</div>
@endsection
