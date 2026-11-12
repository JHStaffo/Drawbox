<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Draw;
use App\Models\InventoryLog;
use App\Models\LockerAssignment;
use App\Models\PermissionProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $profiles = PermissionProfile::ordered();

        return view('admin.users', ['profiles' => $profiles, 'grantable' => $profiles->filter(fn ($p) => request()->user()->canGrantProfile($p))->values(), 'users' => User::with('profile.parent')->get()->sortBy([fn ($a, $b) => $b->active <=> $a->active, fn ($a, $b) => $a->sortName() <=> $b->sortName()])->values()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($error = $this->cantGrant($request, $data['permission_profile_id'])) {
            return back()->withInput()->with('error', $error);
        }
        $user = User::create($data + ['active' => true]);

        return back()->with('status', "Added {$user->name}.");
    }

    /**
     * Add team members from a CSV with First Name, Last Name and Email
     * columns, and an optional Phone column. Everyone gets the default permission
     * profile. Emails already on the roster are skipped, so the same file can be
     * uploaded twice safely, except that someone on the roster with no phone
     * number gets the one in the file.
     */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);

        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), preg_split('/\r\n|\r|\n/', trim(file_get_contents($request->file('file')->getRealPath()))));
        $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0] ?? ''); // Excel's byte order mark

        // Use the header row to find the columns; without one, assume First, Last, Email.
        $header = array_map(fn ($h) => preg_replace('/[^a-z]/', '', strtolower($h)), $rows[0]);
        $find = fn (array $names) => collect($header)->search(fn ($h) => in_array($h, $names, true));
        $first = $find(['firstname', 'first', 'fname']);
        $last = $find(['lastname', 'last', 'lname', 'surname']);
        $email = $find(['email', 'emailaddress', 'mail']);
        $phone = $find(['phone', 'phonenumber', 'mobile', 'mobilephone', 'mobilenumber', 'cell', 'cellphone', 'cellnumber']);
        if ($first === false && $last === false && $email === false) {
            [$first, $last, $email, $phone] = [0, 1, 2, 3];
            $start = 0;
        } elseif ($first === false || $last === false || $email === false) {
            return back()->with('error', 'The CSV needs First Name, Last Name and Email columns.');
        } else {
            $start = 1;
        }

        $existing = User::pluck('email')->flip();
        $added = 0;
        $skipped = 0;
        $phonesAdded = 0;
        $problems = [];
        $badPhones = [];
        $profileId = PermissionProfile::default()?->id;
        if ($profileId && ($error = $this->cantGrant($request, $profileId))) {
            return back()->with('error', $error);
        }
        foreach (array_slice($rows, $start, null, true) as $i => $row) {
            if (count(array_filter($row, fn ($v) => trim($v) !== '')) === 0) {
                continue; // blank line
            }
            $name = trim(trim($row[$first] ?? '').' '.trim($row[$last] ?? ''));
            $address = strtolower(trim($row[$email] ?? ''));
            if ($name === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $problems[] = $i + 1;

                continue;
            }
            $number = null;
            if ($phone !== false && trim($row[$phone] ?? '') !== '') {
                $number = User::normalizePhone($row[$phone]);
                if (! $number) {
                    $badPhones[] = $i + 1;
                }
            }
            if ($existing->has($address)) {
                $skipped++;
                $member = $number ? User::where('email', $address)->whereNull('phone')->first() : null;
                if ($member && $request->user()->canManage($member)) {
                    $member->update(['phone' => $number]);
                    $phonesAdded++;
                }

                continue;
            }
            User::create(['name' => mb_substr($name, 0, 100), 'email' => $address, 'phone' => $number, 'permission_profile_id' => $profileId, 'active' => true]);
            $existing[$address] = true;
            $added++;
        }

        $message = "Added {$added} team member(s).";
        if ($skipped) {
            $message .= " Skipped {$skipped} already on the roster.";
        }
        if ($phonesAdded) {
            $message .= " Added a phone number for {$phonesAdded} already on the roster.";
        }
        if ($problems) {
            $message .= ' Skipped line(s) '.implode(', ', $problems).' with a missing name or email.';
        }
        if ($badPhones) {
            $message .= ' Left out the phone number on line(s) '.implode(', ', $badPhones).' because it isn\'t a valid phone number.';
        }

        return back()->with('status', $message);
    }

    public function update(Request $request, User $user)
    {
        if (! $request->user()->canManage($user)) {
            return back()->with('error', "{$user->name} has access you don't have, so only someone with at least that access can change them.");
        }
        $data = $this->validated($request, $user) + ['active' => $request->boolean('active')];
        if ($error = $this->cantGrant($request, $data['permission_profile_id'])) {
            return back()->with('error', $error);
        }
        $keepsAccess = PermissionProfile::find($data['permission_profile_id'])?->allows('team_members');
        if ($user->is($request->user()) && (! $keepsAccess || ! $data['active'])) {
            return back()->with('error', "You can't take away your own access to Team Members.");
        }
        $user->update($data);

        return back()->with('status', "Saved {$user->name}.");
    }

    /**
     * Delete someone added by mistake. Anyone with prize history, or who has
     * credited calls or handed out prizes, is turned off instead so the
     * records stay for taxes. Draws they were credited but never used go too.
     */
    public function destroy(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            return back()->with('error', "You can't delete yourself.");
        }
        if (! $request->user()->canManage($user)) {
            return back()->with('error', "{$user->name} has access you don't have, so only someone with at least that access can delete them.");
        }

        $history = $user->draws()->exists()
            || LockerAssignment::where('user_id', $user->id)->orWhere('dispensed_by', $user->id)->exists()
            || Call::where('created_by', $user->id)->exists()
            || Draw::where('awarded_by', $user->id)->exists();
        if ($history) {
            return back()->with('error', "{$user->name} has prize or dispatch history, so they can't be deleted. Uncheck Active and Save to turn them off instead.");
        }

        DB::transaction(function () use ($user) {
            InventoryLog::where('user_id', $user->id)->update(['user_id' => null]);
            $user->credits()->delete();
            $user->delete();
        });

        return back()->with('status', "Deleted {$user->name}.");
    }

    /**
     * Nobody can hand out a profile with access they don't have themselves,
     * so a supervisor who can edit team members can't make anyone an admin.
     */
    private function cantGrant(Request $request, int|string $profileId): ?string
    {
        $profile = PermissionProfile::findOrFail($profileId);

        return $request->user()->canGrantProfile($profile)
            ? null
            : "The {$profile->label()} profile has access you don't have, so you can't give it to anyone.";
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:30', function ($attribute, $value, $fail) {
                if (! User::normalizePhone($value)) {
                    $fail('The phone number needs 10 digits, like (757) 555-1234.');
                }
            }],
            'employee_number' => 'nullable|string|max:50',
            'permission_profile_id' => 'required|exists:permission_profiles,id',
        ]);
        $data['phone'] = User::normalizePhone($data['phone'] ?? null);

        return $data;
    }
}
