@extends('layouts.app', ['title' => 'Forms'])

@section('band')
    <h1>Forms</h1>
    <p>Fill one out and it's saved here and posted to its Slack channel.</p>
@endsection

@section('content')
<div class="stack">
    @if ($forms->isEmpty())
        <div class="card"><p class="empty">No forms yet.@if (auth()->user()->hasPermission('manage_forms')) Build one in <a href="{{ route('admin.forms') }}">Admin &gt; Forms</a>.@endif</p></div>
    @else
        <div class="grid grid-2">
            @foreach ($forms as $form)
                <a class="card form-tile" href="{{ route('forms.show', $form) }}">
                    <h2>{{ $form->name }}</h2>
                    @if ($form->description)<p class="hint">{{ Str::limit($form->description, 160) }}</p>@endif
                    @if ($form->channelLabel())<span class="pill pill-teal">Posts to {{ $form->channelLabel() }}</span>@endif
                </a>
            @endforeach
        </div>
    @endif

    @if ($mine->isNotEmpty())
        <div class="card">
            <h2>What you've sent lately</h2>
            <div class="table-wrap"><table>
                <tr><th>When</th><th>Form</th><th>Slack</th></tr>
                @foreach ($mine as $s)
                    <tr>
                        <td>{{ $s->created_at->format('M j g:i A') }}</td>
                        <td>{{ $s->form_name }}</td>
                        <td><span class="pill {{ $s->statusClass() }}">{{ $s->statusLabel() }}</span></td>
                    </tr>
                @endforeach
            </table></div>
        </div>
    @endif
</div>
@endsection
