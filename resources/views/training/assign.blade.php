@extends('layouts.app', ['title' => 'Assign training'])

@section('band')
    <h1>Assign training</h1>
    <p>Give courses or a training profile to Team Members. Each person sees them under My Training.</p>
@endsection

@section('content')
<form method="post" action="{{ route('training.assign.store') }}" class="stack">@csrf
    <div class="card">
        <h2>What</h2>
        @php($what = old('what', $pick['profile'] ? 'profile' : 'course'))
        <label class="check"><input type="radio" name="what" value="course" @checked($what === 'course')> Courses</label>
        <div class="crew-list mt" id="pick-course" style="max-height:220px">
            @forelse ($courses as $c)
                <label><input type="checkbox" name="course_ids[]" value="{{ $c->id }}" @checked(in_array($c->id, old('course_ids', [$pick['course']])))> {{ $c->title }}</label>
            @empty
                <span class="hint">No published courses yet.</span>
            @endforelse
        </div>
        <label class="check mt"><input type="radio" name="what" value="profile" @checked($what === 'profile')> A training profile</label>
        <select name="training_profile_id" aria-label="Training profile" class="mt" style="max-width:420px">
            <option value="">Pick one</option>
            @foreach ($profiles as $p)<option value="{{ $p->id }}" @selected(old('training_profile_id', $pick['profile']) == $p->id)>{{ $p->name }} ({{ $p->courses->count() }} {{ Str::plural('course', $p->courses->count()) }})</option>@endforeach
        </select>
    </div>

    <div class="card">
        <h2>Who</h2>
        <div class="field">
            <label for="who-filter">Team Members</label>
            <input type="search" id="who-filter" placeholder="Type a name to filter">
            <div class="crew-list mt" id="who">
                @foreach ($users as $u)
                    <label data-name="{{ strtolower($u->name) }}"><input type="checkbox" name="user_ids[]" value="{{ $u->id }}" @checked(in_array($u->id, old('user_ids', [])))> {{ $u->name }} <span class="hint">{{ $u->profile?->label() }}</span></label>
                @endforeach
            </div>
        </div>
        <div class="field">
            <label>And everyone on these permission profiles</label>
            <div class="crew-list" style="max-height:200px">
                @foreach ($permissionProfiles as $p)
                    <label><input type="checkbox" name="permission_profile_ids[]" value="{{ $p->id }}" @checked(in_array($p->id, old('permission_profile_ids', [])))> {{ $p->label() }}</label>
                @endforeach
            </div>
            <div class="hint">A main profile includes its sub-profiles. Only active Team Members are assigned.</div>
        </div>
    </div>

    <div class="card">
        <h2>Details</h2>
        <div class="field-row">
            <div class="field"><label for="due">Due date</label><input id="due" type="date" name="due_on" value="{{ old('due_on') }}" min="{{ today()->toDateString() }}"><div class="hint">Blank uses each course's (or the training profile's) days to finish.</div></div>
            <div class="field"><label for="reason">Note to them</label><input id="reason" type="text" name="reason" value="{{ old('reason') }}" maxlength="255" placeholder="Annual refresher"></div>
        </div>
        <div class="field">
            <label>If someone already has the course</label>
            <label class="check"><input type="radio" name="existing" value="skip" @checked(old('existing', 'skip') === 'skip')> Leave theirs alone</label>
            <label class="check"><input type="radio" name="existing" value="reassign" @checked(old('existing') === 'reassign')> Reassign it so they do it again (their earlier results stay as history)</label>
        </div>
        <button class="btn" type="submit">Assign</button>
    </div>
</form>
<script>
document.getElementById('who-filter').addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    document.querySelectorAll('#who label').forEach(function (l) { l.hidden = q && l.dataset.name.indexOf(q) === -1; });
});
</script>
@endsection
