<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PermissionProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function index()
    {
        return view('admin.profiles', [
            'profiles' => PermissionProfile::withCount('users')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $profile = PermissionProfile::create($this->validated($request));

        return back()->with('status', "Added the {$profile->name} profile.");
    }

    public function update(Request $request, PermissionProfile $profile)
    {
        $data = $this->validated($request, $profile);
        // Keep at least the person saving able to get back to this page.
        if ($request->user()->permission_profile_id === $profile->id && ! in_array('profiles', $data['permissions'], true)) {
            return back()->with('error', "You can't take Permission Profiles away from your own profile.");
        }
        $profile->update($data);

        return back()->with('status', "Saved the {$profile->name} profile.");
    }

    public function setDefault(Request $request)
    {
        $data = $request->validate(['profile' => 'required|exists:permission_profiles,id']);
        PermissionProfile::where('id', '!=', $data['profile'])->update(['is_default' => false]);
        $profile = PermissionProfile::findOrFail($data['profile']);
        $profile->update(['is_default' => true]);

        return back()->with('status', "New and imported team members now get the {$profile->name} profile.");
    }

    public function destroy(PermissionProfile $profile)
    {
        if ($profile->users()->exists()) {
            return back()->with('error', "The {$profile->name} profile still has team members. Move them to another profile first.");
        }
        if ($profile->is_default) {
            return back()->with('error', "The {$profile->name} profile is the one new team members get. Pick another one first.");
        }
        $profile->delete();

        return back()->with('status', "Deleted the {$profile->name} profile.");
    }

    private function validated(Request $request, ?PermissionProfile $profile = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('permission_profiles')->ignore($profile)],
            'permissions' => 'array',
            'permissions.*' => [Rule::in(array_keys(PermissionProfile::permissionLabels()))],
        ]);

        // Stored in menu order so the audit log reads the same way every time.
        $picked = $data['permissions'] ?? [];

        return [
            'name' => trim($data['name']),
            'permissions' => array_values(array_filter(array_keys(PermissionProfile::permissionLabels()), fn ($k) => in_array($k, $picked, true))),
        ];
    }
}
