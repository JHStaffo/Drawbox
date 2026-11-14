<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Draw;
use App\Models\InventoryLog;
use App\Models\LockerAssignment;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\Ssn;
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
        $ssn = $this->validatedSsn($request);
        $dob = $this->validatedDob($request);
        if ($error = $this->cantGrant($request, $data['permission_profile_id'])) {
            return back()->withInput($request->except('ssn', 'dob'))->with('error', $error);
        }
        $user = new User($data + ['active' => true]);
        if ($ssn) {
            $user->setSsn($ssn);
        }
        if ($dob) {
            $user->setDob($dob);
        }
        $user->save();
        $saved = array_filter([$ssn ? 'SSN' : null, $dob ? 'date of birth' : null]);

        return back()->with('status', "Added {$user->name}.".($saved ? ' '.ucfirst(implode(' and ', $saved)).' saved.' : ''));
    }

    /**
     * The full SSN and date of birth, for someone with the See SSNs and dates
     * of birth permission, one Team Member at a time. Every look is in the
     * audit log; the values themselves never are.
     */
    public function revealSsn(Request $request, User $user)
    {
        $what = implode(' and ', array_filter([$user->hasSsn() ? 'full SSN' : null, $user->hasDob() ? 'date of birth' : null]));
        if ($what === '') {
            audit_note("Tried to look at {$user->name}'s SSN and date of birth, but neither is on file.");

            return response()->json(['message' => 'Nothing on file.'], 404);
        }
        try {
            $ssn = $user->ssn();
            $dob = $user->dob();
        } catch (\Throwable $e) {
            audit_note("Tried to look at {$user->name}'s {$what}, but it couldn't be decrypted.");

            return response()->json(['message' => "Couldn't read it. The SSN key may have changed."], 500);
        }
        audit_note("Looked at {$user->name}'s {$what}.");

        return response()->json(['ssn' => $ssn, 'dob' => $dob ? \Carbon\Carbon::parse($dob)->format('m/d/Y') : null])
            ->header('Cache-Control', 'no-store, private');
    }

    /** Add, replace or remove one Team Member's date of birth (the same permission as SSNs). */
    public function updateDob(Request $request, User $user)
    {
        if (! $request->user()->canManage($user)) {
            return back()->with('error', "{$user->name} has access you don't have, so you can't change their date of birth.");
        }
        if ($request->boolean('remove')) {
            if (! $user->hasDob()) {
                return back()->with('error', "{$user->name} has no date of birth on file.");
            }
            $user->setDob(null);
            $user->save();

            return back()->with('status', "Removed {$user->name}'s date of birth.");
        }

        $dob = $this->validatedDob($request, required: true);
        $had = $user->hasDob();
        if ($had && $user->dob() === $dob) {
            return back()->with('status', "{$user->name}'s date of birth is unchanged.");
        }
        $user->setDob($dob);
        $user->save();

        return back()->with('status', ($had ? 'Changed' : 'Saved')." {$user->name}'s date of birth.");
    }

    /** Add, replace or remove one Team Member's SSN (the Add, change or remove SSNs permission). */
    public function updateSsn(Request $request, User $user)
    {
        if (! $request->user()->canManage($user)) {
            return back()->with('error', "{$user->name} has access you don't have, so you can't change their SSN.");
        }
        if ($request->boolean('remove')) {
            if (! $user->hasSsn()) {
                return back()->with('error', "{$user->name} has no SSN on file.");
            }
            $user->setSsn(null);
            $user->save();

            return back()->with('status', "Removed {$user->name}'s SSN.");
        }

        $ssn = $this->validatedSsn($request, $user, required: true);
        $had = $user->hasSsn();
        if ($had && $user->ssn_hash === Ssn::hash($ssn)) {
            return back()->with('status', "{$user->name}'s SSN is unchanged.");
        }
        $user->setSsn($ssn);
        $user->save();

        return back()->with('status', ($had ? 'Changed' : 'Saved')." {$user->name}'s SSN.");
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
        $ssnColumn = $find(['ssn', 'socialsecuritynumber', 'socialsecurity', 'socialsecurityno', 'socialsecno', 'ssnumber']);
        $dobColumn = $find(['dob', 'dateofbirth', 'birthdate', 'birthday', 'birthdat']);
        if ($first === false && $last === false && $email === false) {
            [$first, $last, $email, $phone] = [0, 1, 2, 3];
            $ssnColumn = $dobColumn = false; // only read with a header, so a stray column is never taken for an SSN or birthday
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
        $ssnsAdded = 0;
        $badSsns = [];
        $ssnSkipped = ($ssnColumn !== false || $dobColumn !== false) && ! ($request->user()->hasPermission('ssn_edit') && Ssn::configured());
        if ($ssnSkipped) {
            $ssnColumn = $dobColumn = false;
        }
        $dobsAdded = 0;
        $badDobs = [];
        $seenSsns = [];
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
            // An SSN that isn't valid, is in the file twice or is already someone else's is left out.
            $ssn = null;
            if ($ssnColumn !== false && trim($row[$ssnColumn] ?? '') !== '') {
                $ssn = Ssn::normalize($row[$ssnColumn]);
                $owner = $ssn ? User::withSsn($ssn) : null;
                if (! $ssn || isset($seenSsns[$ssn]) || ($owner && $owner->email !== $address)) {
                    $badSsns[] = $i + 1;
                    $ssn = null;
                } else {
                    $seenSsns[$ssn] = true;
                    if ($owner) {
                        $ssn = null; // already theirs
                    }
                }
            }
            $dob = null;
            if ($dobColumn !== false && trim($row[$dobColumn] ?? '') !== '') {
                $dob = Ssn::normalizeDob($row[$dobColumn]);
                if (! $dob) {
                    $badDobs[] = $i + 1;
                }
            }
            if ($existing->has($address)) {
                $skipped++;
                $member = $dob ? User::where('email', $address)->whereNull('dob_encrypted')->first() : null;
                if ($member && $request->user()->canManage($member)) {
                    $member->setDob($dob);
                    $member->save();
                    $dobsAdded++;
                }
                $member = $number ? User::where('email', $address)->whereNull('phone')->first() : null;
                if ($member && $request->user()->canManage($member)) {
                    $member->update(['phone' => $number]);
                    $phonesAdded++;
                }
                $member = $ssn ? User::where('email', $address)->whereNull('ssn_encrypted')->first() : null;
                if ($member && $request->user()->canManage($member)) {
                    $member->setSsn($ssn);
                    $member->save();
                    $ssnsAdded++;
                }

                continue;
            }
            $user = new User(['name' => mb_substr($name, 0, 100), 'email' => $address, 'phone' => $number, 'permission_profile_id' => $profileId, 'active' => true]);
            if ($ssn) {
                $user->setSsn($ssn);
                $ssnsAdded++;
            }
            if ($dob) {
                $user->setDob($dob);
                $dobsAdded++;
            }
            $user->save();
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
        if ($ssnsAdded) {
            $message .= " Saved {$ssnsAdded} SSN(s).";
        }
        if ($dobsAdded) {
            $message .= " Saved {$dobsAdded} date(s) of birth.";
        }
        if ($ssnSkipped) {
            $message .= ' Left out the SSN and date of birth columns because you can\'t add them.';
        }
        if ($problems) {
            $message .= ' Skipped line(s) '.implode(', ', $problems).' with a missing name or email.';
        }
        if ($badPhones) {
            $message .= ' Left out the phone number on line(s) '.implode(', ', $badPhones).' because it isn\'t a valid phone number.';
        }

        if ($badSsns) {
            $message .= ' Left out the SSN on line(s) '.implode(', ', $badSsns).' because it isn\'t valid or is already on file for someone else.';
        }

        if ($badDobs) {
            $message .= ' Left out the date of birth on line(s) '.implode(', ', $badDobs).' because it isn\'t a valid date, like 4/12/1985.';
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

    /**
     * Nine digits from the ssn field, or null when it's blank. Only someone
     * with the Add, change or remove SSNs permission can send one. Error
     * messages never repeat the number.
     */
    private function validatedSsn(Request $request, ?User $user = null, bool $required = false): ?string
    {
        $value = trim((string) $request->input('ssn'));
        if ($value === '' && ! $required) {
            return null;
        }
        $fail = fn (string $message) => throw \Illuminate\Validation\ValidationException::withMessages(['ssn' => $message]);
        if (! $request->user()->hasPermission('ssn_edit')) {
            $fail("You can't add SSNs.");
        }
        if (! Ssn::configured()) {
            $fail("SSNs can't be saved until the server's SSN key is set.");
        }
        $ssn = Ssn::normalize($value);
        if (! $ssn) {
            $fail($value === '' ? 'Type the SSN.' : 'That isn\'t a valid SSN. Use 9 digits, like 123-45-6789.');
        }
        $owner = User::withSsn($ssn);
        if ($owner && ! $owner->is($user)) {
            $fail('That SSN is already on file for another Team Member.');
        }

        return $ssn;
    }

    /** A date of birth as Y-m-d, or null when it's blank. Same permission as SSNs; errors never repeat the date. */
    private function validatedDob(Request $request, bool $required = false): ?string
    {
        $value = trim((string) $request->input('dob'));
        if ($value === '' && ! $required) {
            return null;
        }
        $fail = fn (string $message) => throw \Illuminate\Validation\ValidationException::withMessages(['dob' => $message]);
        if (! $request->user()->hasPermission('ssn_edit')) {
            $fail("You can't add dates of birth.");
        }
        if (! Ssn::configured()) {
            $fail("Dates of birth can't be saved until the server's SSN key is set.");
        }

        return Ssn::normalizeDob($value) ?? $fail($value === '' ? 'Pick the date of birth.' : 'That isn\'t a valid date of birth.');
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
