@extends('layouts.app', ['title' => $profile->exists ? $profile->name : 'New training profile'])

@section('band')
    <p style="margin:0 0 .3em"><a style="color:#fff" href="{{ route('training.profiles') }}">Training profiles</a></p>
    <h1>{{ $profile->exists ? $profile->name : 'New training profile' }}</h1>
@endsection

@section('content')
<form method="post" action="{{ $profile->exists ? route('training.profiles.update', $profile) : route('training.profiles.store') }}" class="stack">@csrf
    @if ($profile->exists) @method('put') @endif
    <div class="card">
        <div class="field"><label for="name">Name</label><input id="name" type="text" name="name" value="{{ old('name', $profile->name) }}" required maxlength="255" placeholder="New Hire Orientation"></div>
        <div class="field"><label for="desc">Description</label><textarea id="desc" name="description" rows="2" maxlength="2000">{{ old('description', $profile->description) }}</textarea></div>
        <div class="field"><label for="due">Days to finish</label><input id="due" type="number" name="due_days" min="1" max="3650" style="max-width:200px" value="{{ old('due_days', $profile->due_days) }}" placeholder="Each course's own"><div class="hint">Counted from the day it's given.</div></div>
    </div>
    <div class="card">
        <h2>Courses</h2>
        <p class="hint">Tick the courses and number them in the order they should be taken. Draft courses are given once they're published, the next time the profile is assigned.</p>
        <div class="table-wrap"><table>
            <thead><tr><th style="width:40px"></th><th style="width:90px">Order</th><th>Course</th><th>Status</th></tr></thead>
            <tbody>
            @foreach ($courses as $c)
                @php($pos = array_search($c->id, $chosen))
                <tr>
                    <td><input type="checkbox" name="course_ids[]" value="{{ $c->id }}" aria-label="Include {{ $c->title }}" @checked(in_array($c->id, old('course_ids', $chosen)))></td>
                    <td><input type="number" name="order[{{ $c->id }}]" min="0" max="999" value="{{ old('order.'.$c->id, $pos === false ? '' : $pos + 1) }}" aria-label="Order of {{ $c->title }}"></td>
                    <td>{{ $c->title }}</td>
                    <td><span @class(['pill', 'pill-green' => $c->isPublished()])>{{ $c->statusLabel() }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
    <div class="card">
        <h2>Give automatically</h2>
        <p class="hint">New Team Members on these permission profiles, and anyone moved onto one, get this training profile's courses automatically (skipping courses they already have). A main profile includes its sub-profiles.</p>
        <div class="crew-list">
            @foreach ($permissionProfiles as $p)
                <label><input type="checkbox" name="auto_ids[]" value="{{ $p->id }}" @checked(in_array($p->id, old('auto_ids', $auto)))> {{ $p->label() }}</label>
            @endforeach
        </div>
    </div>
    <div class="row-actions">
        <button class="btn" type="submit">Save</button>
        <a class="btn btn-outline" href="{{ route('training.profiles') }}">Cancel</a>
    </div>
</form>
@if ($profile->exists)
    <form method="post" action="{{ route('training.profiles.destroy', $profile) }}" class="mt" onsubmit="return confirm('Delete this training profile? Courses already given through it stay assigned.')">@csrf @method('delete')
        <button class="btn btn-small btn-outline" type="submit">Delete training profile</button>
    </form>
@endif
@endsection
