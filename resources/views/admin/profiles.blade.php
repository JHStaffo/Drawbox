@extends('layouts.app', ['title' => 'Permission Profiles'])

@section('band')
    <h1>Permission Profiles</h1>
    <p>Each team member has one profile, and it decides which pages they can open. Everyone can draw prizes they've earned. Pick a team member's profile on the Team Members page.</p>
@endsection

@section('content')
<div class="stack">
    <form class="card" method="post" action="{{ route('admin.profiles.default') }}" style="max-width:640px">@csrf @method('put')
        <h2>New team members</h2>
        <div class="field">
            <label for="default-profile">Profile for people added or imported</label>
            <select id="default-profile" name="profile">@foreach ($profiles as $p)<option value="{{ $p->id }}" @selected($p->is_default)>{{ $p->name }}</option>@endforeach</select>
        </div>
        <button class="btn" type="submit">Save</button>
    </form>

    @foreach ($profiles as $profile)
        <form class="card" method="post" action="{{ route('admin.profiles.update', $profile) }}">@csrf @method('put')
            <div class="profile-head">
                <div class="field" style="margin:0;flex:1;max-width:320px"><label for="pn{{ $profile->id }}">Profile name</label><input id="pn{{ $profile->id }}" type="text" name="name" value="{{ $profile->name }}" required maxlength="60"></div>
                <div class="hint">{{ $profile->users_count }} {{ $profile->users_count === 1 ? 'team member' : 'team members' }}@if ($profile->is_default) · {{ "new team members get this one" }}@endif</div>
            </div>
            @include('admin.partials.profile-permissions', ['picked' => $profile->permissions ?? []])
            <div class="row-actions">
                <button class="btn btn-teal" type="submit">Save</button>
                @if ($profile->users_count === 0 && ! $profile->is_default)
                    <button class="btn btn-outline" style="color:var(--red)" type="submit" form="del{{ $profile->id }}">Delete</button>
                @endif
            </div>
        </form>
        @if ($profile->users_count === 0 && ! $profile->is_default)
            <form id="del{{ $profile->id }}" method="post" action="{{ route('admin.profiles.destroy', $profile) }}" onsubmit="return confirm('Delete the {{ $profile->name }} profile?')">@csrf @method('delete')</form>
        @endif
    @endforeach

    <form class="card" method="post" action="{{ route('admin.profiles.store') }}">@csrf
        <h2>Add a profile</h2>
        <div class="field" style="max-width:320px"><label for="new-profile">Profile name</label><input id="new-profile" type="text" name="name" value="{{ old('name') }}" required maxlength="60"></div>
        @include('admin.partials.profile-permissions', ['picked' => old('permissions', [])])
        <button class="btn" type="submit">Add profile</button>
    </form>
</div>
@endsection
