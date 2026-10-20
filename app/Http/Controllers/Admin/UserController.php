<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return view('admin.users', ['users' => User::orderBy('active', 'desc')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $user = User::create($this->validated($request) + ['active' => true]);

        return back()->with('status', "Added {$user->name}.");
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user) + ['active' => $request->boolean('active')];
        if ($user->is($request->user()) && ($data['role'] !== 'admin' || ! $data['active'])) {
            return back()->with('error', "You can't remove your own admin access.");
        }
        $user->update($data);

        return back()->with('status', "Saved {$user->name}.");
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        return $request->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user)],
            'employee_number' => 'nullable|string|max:50',
            'role' => ['required', Rule::in(User::ROLES)],
        ]);
    }
}
