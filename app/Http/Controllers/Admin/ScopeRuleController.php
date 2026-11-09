<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LiftRule;
use App\Models\ScopeItem;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScopeRuleController extends Controller
{
    public function index()
    {
        return view('admin.scope', [
            'items' => ScopeItem::orderBy('kind')->orderBy('name')->get(),
            'rules' => LiftRule::orderBy('id')->get(),
            'baseCrew' => Setting::get('scope_base_crew'),
            'baseTrucks' => Setting::get('scope_base_trucks'),
            'sceneTypes' => Setting::get('scope_scene_types'),
            'destinationTypes' => Setting::get('scope_destination_types'),
        ]);
    }

    public function storeItem(Request $request)
    {
        $item = ScopeItem::create($this->itemData($request));

        return back()->with('status', "Added {$item->name} ({$item->levelLabel()}).");
    }

    public function updateItem(Request $request, ScopeItem $item)
    {
        $item->update($this->itemData($request));

        return back()->with('status', "Saved {$item->name}.");
    }

    public function destroyItem(ScopeItem $item)
    {
        $item->delete();

        return back()->with('status', "Deleted {$item->name}.");
    }

    public function storeRule(Request $request)
    {
        $rule = LiftRule::create($this->ruleData($request));

        return back()->with('status', "Added lift rule #{$rule->id}: {$rule->conditions()}.");
    }

    public function updateRule(Request $request, LiftRule $rule)
    {
        $rule->update($this->ruleData($request));

        return back()->with('status', "Saved lift rule #{$rule->id}.");
    }

    public function destroyRule(LiftRule $rule)
    {
        $rule->delete();

        return back()->with('status', "Deleted lift rule #{$rule->id}: {$rule->conditions()}.");
    }

    public function updateOptions(Request $request)
    {
        $data = $request->validate([
            'scope_base_crew' => 'required|integer|min:1|max:20',
            'scope_base_trucks' => 'required|integer|min:1|max:10',
            'scope_scene_types' => 'nullable|string|max:2000',
            'scope_destination_types' => 'nullable|string|max:2000',
        ]);
        foreach ($data as $key => $value) {
            Setting::put($key, trim((string) $value));
        }

        return back()->with('status', 'Scope check options saved.');
    }

    /**
     * Fill an empty rule set with examples so admins can see how it works.
     * They are marked as examples and are meant to be replaced with the
     * company's own policy.
     */
    public function loadExamples()
    {
        if (ScopeItem::exists() || LiftRule::exists()) {
            return back()->with('error', 'Examples can only be loaded when there are no items or lift rules yet.');
        }

        $note = 'Example: replace with your policy';
        foreach ([
            ['Oxygen (nasal cannula or mask)', 'equipment', 'bls'],
            ['Saline lock', 'equipment', 'bls'],
            ['Cardiac monitor', 'equipment', 'als'],
            ['IV fluids running (no medications)', 'equipment', 'als'],
            ['CPAP', 'equipment', 'als'],
            ['IV pump', 'equipment', 'cct'],
            ['Ventilator', 'equipment', 'cct'],
            ['Pain medication as needed', 'drug', 'als'],
            ['Heparin drip', 'drug', 'cct'],
            ['Nitroglycerin drip', 'drug', 'cct'],
            ['Diltiazem (Cardizem) drip', 'drug', 'cct'],
            ['Blood products', 'drug', 'cct'],
        ] as [$name, $kind, $level]) {
            ScopeItem::create(['name' => $name, 'kind' => $kind, 'level' => $level, 'note' => $note]);
        }

        LiftRule::create(['min_weight_lbs' => 350, 'crew' => 3, 'trucks' => 1, 'note' => $note]);
        LiftRule::create(['min_weight_lbs' => 500, 'crew' => 4, 'trucks' => 2, 'note' => $note]);
        LiftRule::create(['min_steps' => 10, 'crew' => 3, 'trucks' => 1, 'note' => $note]);
        LiftRule::create(['scene_type' => 'Apartment, no elevator', 'min_weight_lbs' => 250, 'crew' => 4, 'trucks' => 2, 'note' => $note]);

        if (Setting::get('scope_scene_types') === '') {
            Setting::put('scope_scene_types', "Residence\nApartment, no elevator\nApartment with elevator\nNursing home\nHospital\nDialysis center\nDoctor's office");
        }
        if (Setting::get('scope_destination_types') === '') {
            Setting::put('scope_destination_types', "Residence\nApartment, no elevator\nApartment with elevator\nNursing home\nHospital\nDialysis center\nDoctor's office");
        }

        return back()->with('status', 'Example rules loaded. Each one is marked as an example: change or delete them to match your policy.');
    }

    private function itemData(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'kind' => ['required', Rule::in(array_keys(ScopeItem::KINDS))],
            'level' => ['required', Rule::in(array_keys(ScopeItem::LEVELS))],
            'note' => 'nullable|string|max:255',
        ]);

        return array_merge(['note' => null], $data);
    }

    private function ruleData(Request $request): array
    {
        $data = $request->validate([
            'scene_type' => 'nullable|string|max:100',
            'destination_type' => 'nullable|string|max:100',
            'min_weight_lbs' => 'nullable|integer|min:1|max:2000',
            'min_steps' => 'nullable|integer|min:1|max:500',
            'crew' => 'required|integer|min:1|max:20',
            'trucks' => 'required|integer|min:1|max:10',
            'note' => 'nullable|string|max:255',
        ]);
        $data = array_merge(['scene_type' => null, 'destination_type' => null, 'min_weight_lbs' => null, 'min_steps' => null, 'note' => null], $data);
        if (! $data['scene_type'] && ! $data['destination_type'] && ! $data['min_weight_lbs'] && ! $data['min_steps']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['crew' => 'Give the rule at least one condition: a scene, destination, weight or steps.']);
        }

        return $data;
    }
}
