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
        return view('admin.users', ['users' => User::all()->sortBy([fn ($a, $b) => $b->active <=> $a->active, fn ($a, $b) => $a->sortName() <=> $b->sortName()])->values()]);
    }

    public function store(Request $request)
    {
        $user = User::create($this->validated($request) + ['active' => true]);

        return back()->with('status', "Added {$user->name}.");
    }

    /**
     * Add team members from a CSV with First Name, Last Name and Email
     * columns. Everyone is added as a Team Member. Emails already on the
     * roster are skipped, so the same file can be uploaded twice safely.
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
        if ($first === false && $last === false && $email === false) {
            [$first, $last, $email] = [0, 1, 2];
            $start = 0;
        } elseif ($first === false || $last === false || $email === false) {
            return back()->with('error', 'The CSV needs First Name, Last Name and Email columns.');
        } else {
            $start = 1;
        }

        $existing = User::pluck('email')->flip();
        $added = 0;
        $skipped = 0;
        $problems = [];
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
            if ($existing->has($address)) {
                $skipped++;

                continue;
            }
            User::create(['name' => mb_substr($name, 0, 100), 'email' => $address, 'role' => 'employee', 'active' => true]);
            $existing[$address] = true;
            $added++;
        }

        $message = "Added {$added} team member(s).";
        if ($skipped) {
            $message .= " Skipped {$skipped} already on the roster.";
        }
        if ($problems) {
            $message .= ' Skipped line(s) '.implode(', ', $problems).' with a missing name or email.';
        }

        return back()->with('status', $message);
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
