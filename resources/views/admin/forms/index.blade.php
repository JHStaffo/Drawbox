@extends('layouts.app', ['title' => 'Forms'])

@section('band')
    <h1>Forms</h1>
    <p>Build the forms team members fill out, and pick which Slack channel each one posts to.</p>
@endsection

@section('content')
<div class="stack">
    @unless ($connected)
        <div class="flash flash-error">Slack isn't connected, so forms will save but not post. <a href="{{ route('admin.slack') }}">Connect Slack</a>.</div>
    @endunless
    @if ($failed)
        <div class="flash flash-error">{{ $failed }} {{ Str::plural('submission', $failed) }} didn't post to Slack. <a href="{{ route('admin.forms.submissions', ['status' => 'failed']) }}">See {{ $failed === 1 ? 'it' : 'them' }}</a>.</div>
    @endif

    <div class="card">
        <div class="row-actions" style="justify-content:space-between">
            <h2 style="margin:0">All forms ({{ $forms->count() }})</h2>
            <a class="btn btn-small btn-outline" href="{{ route('admin.forms.submissions') }}">All submissions</a>
        </div>
        @if ($forms->isEmpty())
            <p class="empty">No forms yet. Add the first one below.</p>
        @else
            <div class="table-wrap mt"><table>
                <tr><th>Form</th><th>Slack channel</th><th class="num">Questions</th><th class="num">Submissions</th><th>Status</th><th></th></tr>
                @foreach ($forms as $form)
                    <tr>
                        <td><a href="{{ route('admin.forms.edit', $form) }}">{{ $form->name }}</a></td>
                        <td>{{ $form->channelLabel() ?? '—' }}</td>
                        <td class="num">{{ $form->fields_count }}</td>
                        <td class="num"><a href="{{ route('admin.forms.submissions', ['form' => $form->id]) }}">{{ $form->submissions_count }}</a></td>
                        <td>@if ($form->active)<span class="pill pill-green">On</span>@else<span class="pill">Off</span>@endif</td>
                        <td class="right"><a class="btn btn-small" href="{{ route('admin.forms.edit', $form) }}">Edit</a></td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>

    <form class="card" method="post" action="{{ route('admin.forms.store') }}" style="max-width:520px">@csrf
        <h2>Add a form</h2>
        <div class="field"><label for="name">Form name</label><input id="name" type="text" name="name" required maxlength="150"></div>
        <button class="btn" type="submit">Add form</button>
    </form>
</div>
@endsection
