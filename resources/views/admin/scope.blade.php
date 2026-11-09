@extends('layouts.app', ['title' => 'Lift assist rules'])

@section('band')
    <h1>Lift assist rules</h1>
    <p>Used by the Scope Check tab. A rule applies when a call meets every condition it sets, and the call needs the most crew and trucks of any rule that applies. The equipment and meds list is in <a href="{{ route('supervisor.settings') }}#scope" style="color:#fff">Settings</a>.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <h2>Lift assist rules ({{ $rules->count() }})</h2>
        <p class="hint">Leave a condition blank to ignore it. A rule needs at least one condition.</p>
        @if ($rules->isNotEmpty())
            <div class="table-wrap"><table>
                <tr><th>#</th><th>Scene</th><th>Destination</th><th class="num">Weight (lbs) at least</th><th class="num">Steps at least</th><th class="num">Crew</th><th class="num">Trucks</th><th>Note</th><th></th></tr>
                @foreach ($rules as $rule)
                    <tr>
                        <td>{{ $rule->id }}</td>
                        <td><input form="r{{ $rule->id }}" type="text" name="scene_type" value="{{ $rule->scene_type }}" list="scene-list" aria-label="Scene"></td>
                        <td><input form="r{{ $rule->id }}" type="text" name="destination_type" value="{{ $rule->destination_type }}" list="destination-list" aria-label="Destination"></td>
                        <td class="num"><input form="r{{ $rule->id }}" type="number" min="1" name="min_weight_lbs" value="{{ $rule->min_weight_lbs }}" style="width:80px" aria-label="Weight"></td>
                        <td class="num"><input form="r{{ $rule->id }}" type="number" min="1" name="min_steps" value="{{ $rule->min_steps }}" style="width:70px" aria-label="Steps"></td>
                        <td class="num"><input form="r{{ $rule->id }}" type="number" min="1" name="crew" value="{{ $rule->crew }}" style="width:60px" required aria-label="Crew"></td>
                        <td class="num"><input form="r{{ $rule->id }}" type="number" min="1" name="trucks" value="{{ $rule->trucks }}" style="width:60px" required aria-label="Trucks"></td>
                        <td><input form="r{{ $rule->id }}" type="text" name="note" value="{{ $rule->note }}" aria-label="Note"></td>
                        <td class="right" style="white-space:nowrap">
                            <form id="r{{ $rule->id }}" method="post" action="{{ route('admin.scope.rules.update', $rule) }}" style="display:inline">@csrf @method('put')<button class="btn btn-small" type="submit">Save</button></form>
                            <form method="post" action="{{ route('admin.scope.rules.destroy', $rule) }}" style="display:inline" onsubmit="return confirm('Delete lift rule #{{ $rule->id }}?')">@csrf @method('delete')<button class="btn btn-small btn-outline" type="submit">Delete</button></form>
                        </td>
                    </tr>
                @endforeach
            </table></div>
        @endif
        <h3 class="mt">Add a lift assist rule</h3>
        <form method="post" action="{{ route('admin.scope.rules.store') }}">@csrf
            <div class="field-row">
                <div class="field"><label>Scene</label><input type="text" name="scene_type" list="scene-list"></div>
                <div class="field"><label>Destination</label><input type="text" name="destination_type" list="destination-list"></div>
                <div class="field"><label>Weight (lbs) at least</label><input type="number" min="1" name="min_weight_lbs"></div>
                <div class="field"><label>Steps at least</label><input type="number" min="1" name="min_steps"></div>
            </div>
            <div class="field-row">
                <div class="field"><label>Crew members</label><input type="number" min="1" name="crew" value="3" required></div>
                <div class="field"><label>Trucks</label><input type="number" min="1" name="trucks" value="1" required></div>
                <div class="field"><label>Note</label><input type="text" name="note"></div>
            </div>
            <button class="btn" type="submit">Add rule</button>
        </form>
        <datalist id="scene-list">@foreach (\App\Services\ScopeCheck::options('scope_scene_types') as $o)<option value="{{ $o }}">@endforeach</datalist>
        <datalist id="destination-list">@foreach (\App\Services\ScopeCheck::options('scope_destination_types') as $o)<option value="{{ $o }}">@endforeach</datalist>
    </div>

    <form class="card" method="post" action="{{ route('admin.scope.options') }}" style="max-width:640px">@csrf @method('put')
        <h2>Options</h2>
        <div class="field-row">
            <div class="field"><label for="scope_base_crew">Usual crew per call</label><input id="scope_base_crew" type="number" min="1" name="scope_base_crew" value="{{ $baseCrew }}" required></div>
            <div class="field"><label for="scope_base_trucks">Usual trucks per call</label><input id="scope_base_trucks" type="number" min="1" name="scope_base_trucks" value="{{ $baseTrucks }}" required></div>
        </div>
        <p class="hint">What a call needs when no lift assist rule applies.</p>
        <div class="field-row">
            <div class="field"><label for="scope_scene_types">Scene types (one per line)</label><textarea id="scope_scene_types" name="scope_scene_types" rows="7">{{ $sceneTypes }}</textarea></div>
            <div class="field"><label for="scope_destination_types">Destination types (one per line)</label><textarea id="scope_destination_types" name="scope_destination_types" rows="7">{{ $destinationTypes }}</textarea></div>
        </div>
        <p class="hint">These are the choices dispatchers pick from. A lift rule's scene or destination must match one of them exactly.</p>
        <button class="btn" type="submit">Save options</button>
    </form>
</div>
@endsection
