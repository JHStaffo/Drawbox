<?php

namespace App\Http\Controllers;

use App\Models\LiftRule;
use App\Models\ScopeItem;
use App\Services\ScopeCheck;
use Illuminate\Http\Request;

class ScopeController extends Controller
{
    /** The scope of practice check. Nothing is saved: it only answers for the call being looked at. */
    public function index(Request $request, ScopeCheck $scope)
    {
        $input = $request->validate([
            'items' => 'array',
            'items.*' => 'integer',
            'scene' => 'nullable|string|max:100',
            'destination' => 'nullable|string|max:100',
            'steps' => 'nullable|integer|min:0|max:500',
            'weight' => 'nullable|integer|min:0|max:2000',
        ]);
        $checked = $request->has('check');

        return view('scope.check', [
            'items' => ScopeItem::orderBy('name')->get(),
            'hasRules' => ScopeItem::exists() || LiftRule::exists(),
            'scenes' => ScopeCheck::options('scope_scene_types'),
            'destinations' => ScopeCheck::options('scope_destination_types'),
            'input' => $input,
            'result' => $checked ? $scope->check(
                $input['items'] ?? [], $input['scene'] ?? null, $input['destination'] ?? null,
                isset($input['steps']) ? (int) $input['steps'] : null, isset($input['weight']) ? (int) $input['weight'] : null,
            ) : null,
        ]);
    }
}
