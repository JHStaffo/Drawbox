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

    private function itemData(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
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
