@extends('layouts.app', ['title' => 'Permission Profiles'])

@section('band')
    <h1>Permission Profiles</h1>
    <p>Each team member has one profile, and it decides which pages they can open. Everyone can draw prizes they've earned. A sub-profile, such as EMT under Team Member, gets everything its main profile allows plus anything checked for it. Pick a team member's profile on the Team Members page.</p>
@endsection

@section('content')
@php($mains = $profiles->whereNull('parent_id'))
<div class="stack">
    <form class="card profile-picker" method="get" action="{{ route('admin.profiles') }}">
        <div class="field" style="margin:0;flex:1;min-width:220px">
            <label for="pick-profile">Profile to edit</label>
            <select id="pick-profile" name="profile" onchange="this.form.submit()">
                @foreach ($profiles as $p)
                    <option value="{{ $p->id }}" @selected($profile?->id === $p->id)>{{ $p->parent_id ? '— ' : '' }}{{ $p->label() }}</option>
                @endforeach
                <option value="new" @selected($adding)>+ Add a profile or sub-profile</option>
            </select>
        </div>
        <noscript><button class="btn" type="submit">Open</button></noscript>
    </form>

    @if ($profile)
        @php($deletable = $profile->users_count === 0 && $profile->children_count === 0 && ! $profile->is_default)
        <form class="card" method="post" action="{{ route('admin.profiles.update', $profile) }}">@csrf @method('put')
            <h2>{{ $profile->label() }}</h2>
            <p class="hint">{{ $profile->users_count }} {{ $profile->users_count === 1 ? 'team member' : 'team members' }}@if ($profile->children_count) · {{ $profile->children_count }} {{ $profile->children_count === 1 ? 'sub-profile' : 'sub-profiles' }}@endif @if ($profile->is_default) · {{ 'new team members get this one' }}@endif</p>
            <div class="field-row" style="max-width:600px">
                <div class="field"><label for="pn">{{ $profile->parent_id ? 'Sub-profile name' : 'Profile name' }}</label><input id="pn" type="text" name="name" value="{{ old('name', $profile->name) }}" required maxlength="60"></div>
                <div class="field"><label for="pp">Sub-profile of</label>
                    @if ($profile->children_count)
                        <select id="pp" disabled><option>None (has sub-profiles)</option></select>
                    @else
                        <select id="pp" name="parent_id"><option value="">None, main profile</option>@foreach ($mains->where('id', '!=', $profile->id) as $m)<option value="{{ $m->id }}" @selected($profile->parent_id === $m->id)>{{ $m->name }}</option>@endforeach</select>
                    @endif
                </div>
            </div>
            @include('admin.partials.profile-permissions', ['picked' => $profile->permissions ?? [], 'inherited' => $profile->inheritedPermissions(), 'from' => $profile->parent?->name])
            <div class="row-actions">
                <button class="btn btn-teal" type="submit">Save</button>
                @if ($deletable)
                    <button class="btn btn-outline" style="color:var(--red)" type="submit" form="del-profile">Delete</button>
                @endif
            </div>
        </form>
        @if ($deletable)
            <form id="del-profile" method="post" action="{{ route('admin.profiles.destroy', $profile) }}" onsubmit="return confirm('Delete the {{ $profile->label() }} profile?')">@csrf @method('delete')</form>
        @endif
    @else
        <form class="card" method="post" action="{{ route('admin.profiles.store') }}">@csrf
            <h2>Add a profile or sub-profile</h2>
            <div class="field-row" style="max-width:600px">
                <div class="field"><label for="new-profile">Name</label><input id="new-profile" type="text" name="name" value="{{ old('name') }}" required maxlength="60" placeholder="EMT"></div>
                <div class="field"><label for="new-parent">Sub-profile of</label>
                    <select id="new-parent" name="parent_id"><option value="">None, main profile</option>@foreach ($mains as $m)<option value="{{ $m->id }}" @selected(old('parent_id') == $m->id)>{{ $m->name }}</option>@endforeach</select>
                </div>
            </div>
            <p class="hint">For a sub-profile, only check what it adds. It already gets everything its main profile allows.</p>
            @include('admin.partials.profile-permissions', ['picked' => old('permissions', []), 'inherited' => [], 'from' => null])
            <button class="btn" type="submit">Add</button>
        </form>
    @endif

    <form class="card" method="post" action="{{ route('admin.profiles.default') }}" style="max-width:640px">@csrf @method('put')
        <h2>New team members</h2>
        <div class="field">
            <label for="default-profile">Profile for people added or imported</label>
            <select id="default-profile" name="profile">@foreach ($profiles as $p)<option value="{{ $p->id }}" @selected($p->is_default)>{{ $p->label() }}</option>@endforeach</select>
        </div>
        <button class="btn" type="submit">Save</button>
    </form>
</div>
@endsection
