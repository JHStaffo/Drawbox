@extends('layouts.app', ['title' => 'Courses'])

@section('band')
    <h1>Courses</h1>
    <p>Build courses from pages, PDFs, PowerPoints, videos, links and quizzes. A course can be assigned once it's published.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <h2>New course</h2>
        <form method="post" action="{{ route('training.courses.store') }}" class="field-row" style="align-items:end">@csrf
            <div class="field" style="grid-column: span 2"><label for="title">Title</label><input id="title" type="text" name="title" required maxlength="255" placeholder="Employee Handbook"></div>
            <div class="field"><button class="btn" type="submit">Start the course</button></div>
        </form>
    </div>
    <div class="card">
        <h2>All courses</h2>
        @if ($courses->isEmpty())
            <p class="empty">No courses yet.</p>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Course</th><th>Status</th><th class="num">Lessons</th><th class="num">In progress</th><th class="num">Completed</th><th>Last change</th><th></th></tr></thead>
                <tbody>
                @foreach ($courses as $c)
                    <tr>
                        <td><a href="{{ route('training.courses.edit', $c) }}">{{ $c->title }}</a>@if ($c->category)<div class="hint">{{ $c->category }}</div>@endif</td>
                        <td><span @class(['pill', 'pill-green' => $c->status === 'published'])>{{ $c->statusLabel() }}</span></td>
                        <td class="num">{{ $c->lessons_count }}</td>
                        <td class="num">{{ $c->open_count }}</td>
                        <td class="num">{{ $c->done_count }}</td>
                        <td>{{ $c->updated_at->format('M j, Y') }}</td>
                        <td>@if ($c->open_count + $c->done_count && auth()->user()->hasAnyPermission('training_assign', 'training_reports'))<a href="{{ route('training.progress', $c) }}">Progress</a>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</div>
@endsection
