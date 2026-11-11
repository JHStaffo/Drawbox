<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PermissionProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /** One profile at a time, picked from a dropdown, so the page stays short however many there are. */
    public function index(Request $request)
    {
        $profiles = PermissionProfile::ordered();
        $adding = $request->query('profile') === 'new';
        $profile = $adding ? null : ($profiles->firstWhere('id', (int) $request->query('profile')) ?? $profiles->first());
        $profile?->loadCount('users', 'children');

        return view('admin.profiles', ['profiles' => $profiles, 'profile' => $profile, 'adding' => $adding]);
    }

    public function store(Request $request)
    {
        $profile = PermissionProfile::create($this->validated($request));

        return redirect()->route('admin.profiles', ['profile' => $profile->id])->with('status', "Added the {$profile->label()} profile.");
    }

    public function update(Request $request, PermissionProfile $profile)
    {
        if ($error = $this->cantEdit($request, $profile)) {
            return back()->with('error', $error);
        }
        $data = $this->validated($request, $profile);

        // Keep the person saving able to get back to this page, whether the
        // change is to their own profile or to the one it sits under.
        DB::beginTransaction();
        $profile->update($data);
        if (! $request->user()->fresh()->hasPermission('profiles')) {
            DB::rollBack();

            return back()->with('error', "You can't take Permission Profiles away from your own profile.");
        }
        DB::commit();

        return back()->with('status', "Saved the {$profile->fresh()->label()} profile.");
    }

    /** A copy with the same permissions and main profile, opened so it can be renamed. Sub-profiles aren't copied. */
    public function duplicate(Request $request, PermissionProfile $profile)
    {
        if ($error = $this->cantEdit($request, $profile)) {
            return back()->with('error', $error);
        }
        $name = "{$profile->name} (copy)";
        for ($n = 2; PermissionProfile::where('name', $name)->exists(); $n++) {
            $name = "{$profile->name} (copy {$n})";
        }
        $copy = PermissionProfile::create([
            'name' => mb_substr($name, 0, 60),
            'parent_id' => $profile->parent_id,
            'permissions' => $profile->permissions ?? [],
            'hide_winners' => $profile->hide_winners,
        ]);

        return redirect()->route('admin.profiles', ['profile' => $copy->id])->with('status', "Made {$copy->label()} from {$profile->label()}. Rename it and change what it can do, then Save.");
    }

    public function setDefault(Request $request)
    {
        $data = $request->validate(['profile' => 'required|exists:permission_profiles,id']);
        $profile = PermissionProfile::findOrFail($data['profile']);
        if (! $request->user()->canGrantProfile($profile)) {
            return back()->with('error', "The {$profile->label()} profile has access you don't have, so you can't give it to new team members.");
        }
        PermissionProfile::where('id', '!=', $profile->id)->update(['is_default' => false]);
        $profile->update(['is_default' => true]);

        return back()->with('status', "New and imported team members now get the {$profile->label()} profile.");
    }

    public function destroy(Request $request, PermissionProfile $profile)
    {
        if ($error = $this->cantEdit($request, $profile)) {
            return back()->with('error', $error);
        }
        if ($profile->users()->exists()) {
            return back()->with('error', "The {$profile->label()} profile still has team members. Move them to another profile first.");
        }
        if ($profile->children()->exists()) {
            return back()->with('error', "The {$profile->name} profile has sub-profiles. Delete or move them first.");
        }
        if ($profile->is_default) {
            return back()->with('error', "The {$profile->label()} profile is the one new team members get. Pick another one first.");
        }
        $profile->delete();

        return redirect()->route('admin.profiles')->with('status', "Deleted the {$profile->label()} profile.");
    }

    /** Profiles (or sub-profiles under them) with access the person lacks are look-only for them. */
    private function cantEdit(Request $request, PermissionProfile $profile): ?string
    {
        return $request->user()->canEditProfile($profile)
            ? null
            : "The {$profile->label()} profile has access you don't have, so you can't change, copy or delete it.";
    }

    private function validated(Request $request, ?PermissionProfile $profile = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('permission_profiles')->ignore($profile)],
            'parent_id' => ['nullable', Rule::exists('permission_profiles', 'id')->whereNull('parent_id')],
            'permissions' => 'array',
            'permissions.*' => [Rule::in(array_keys(PermissionProfile::permissionLabels()))],
        ], ['parent_id.exists' => 'A sub-profile has to sit under a main profile, not another sub-profile.']);

        $parent = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        // Nobody can give a profile access they don't have themselves, whether
        // by checking it or by putting the profile under a main profile that has it.
        $inherited = $parent ? (PermissionProfile::find($parent)->permissions ?? []) : [];
        if (! $request->user()->canGrant(array_merge($inherited, $data['permissions'] ?? []))) {
            throw ValidationException::withMessages(['permissions' => "You can only give a profile access you have yourself."]);
        }
        if ($profile && $parent === $profile->id) {
            throw ValidationException::withMessages(['parent_id' => "A profile can't be a sub-profile of itself."]);
        }
        if ($profile && $parent && $profile->children()->exists()) {
            throw ValidationException::withMessages(['parent_id' => "{$profile->name} has sub-profiles of its own, so it can't become a sub-profile."]);
        }

        // Stored in menu order so the audit log reads the same way every time.
        // A sub-profile only stores what it adds; the rest comes from its parent.
        $picked = array_diff($data['permissions'] ?? [], $inherited);

        return [
            'name' => trim($data['name']),
            'parent_id' => $parent,
            'permissions' => array_values(array_filter(array_keys(PermissionProfile::permissionLabels()), fn ($k) => in_array($k, $picked, true))),
        ];
    }
}
