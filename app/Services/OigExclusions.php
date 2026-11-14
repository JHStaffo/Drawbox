<?php

namespace App\Services;

use App\Models\OigCheck;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks every active Team Member against the OIG's List of Excluded
 * Individuals/Entities (LEIE). The OIG publishes the whole list monthly as
 * UPDATED.csv: names, date of birth and exclusion details, but no SSNs.
 *
 * A Team Member whose first and last name match a row is:
 *  - excluded when the dates of birth match too,
 *  - not excluded when both have a date of birth and they differ,
 *  - "needs manual check" when either date of birth is missing.
 * Every name match (excluded or manual) should be confirmed on the OIG
 * website, which verifies a match with the SSN; the result is recorded on the
 * check (OigController::review).
 */
class OigExclusions
{
    private const SUFFIXES = ['JR', 'SR', 'II', 'III', 'IV', 'V'];

    public function run(?User $by = null): OigCheck
    {
        $path = storage_path('app/oig-leie-'.uniqid().'.csv');
        try {
            $response = Http::timeout(300)->withUserAgent('Fast Track Pulse exclusion check')->sink($path)->get(config('services.oig.url'));
            if (! $response->successful()) {
                return $this->failed($by, "Couldn't download the OIG list (error {$response->status()}).");
            }
            $updated = $response->header('Last-Modified');

            return $this->check($path, $updated ? Carbon::parse($updated) : null, $by);
        } catch (Throwable $e) {
            report($e);

            return $this->failed($by, "Couldn't download the OIG list.");
        } finally {
            @unlink($path);
        }
    }

    /** Check everyone against a downloaded copy of the list. */
    public function check(string $path, ?Carbon $listUpdated, ?User $by): OigCheck
    {
        $members = User::where('active', true)->get();

        // Every way each Team Member's last name could be written => [first name => members].
        $index = [];
        foreach ($members as $member) {
            [$first, $lasts] = self::nameKeys($member->name);
            foreach ($lasts as $last) {
                $index[$last][$first][] = $member->id;
            }
        }

        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtoupper(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle, null, ',', '"', '') ?: []);
        $col = array_flip($header);
        if (! isset($col['LASTNAME'], $col['FIRSTNAME'])) {
            fclose($handle);

            return $this->failed($by, "The OIG list wasn't in the expected format.");
        }

        $rows = 0;
        $matches = []; // member id => list rows
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows++;
            $last = self::key($row[$col['LASTNAME']] ?? '');
            if ($last === '' || ! isset($index[$last])) {
                continue;
            }
            $first = self::key(explode(' ', trim($row[$col['FIRSTNAME']] ?? ''))[0] ?? '');
            foreach ($index[$last][$first] ?? [] as $id) {
                $matches[$id][] = [
                    'name' => trim(($row[$col['FIRSTNAME']] ?? '').' '.($row[$col['MIDNAME']] ?? '').' '.($row[$col['LASTNAME']] ?? '')),
                    'dob' => self::date($row[$col['DOB'] ?? -1] ?? ''),
                    'type' => trim($row[$col['EXCLTYPE'] ?? -1] ?? ''),
                    'excluded_on' => self::date($row[$col['EXCLDATE'] ?? -1] ?? ''),
                    'specialty' => trim(($row[$col['GENERAL'] ?? -1] ?? '').' '.($row[$col['SPECIALTY'] ?? -1] ?? '')),
                    'city' => trim(($row[$col['CITY'] ?? -1] ?? '').', '.($row[$col['STATE'] ?? -1] ?? ''), ', '),
                    'npi' => trim($row[$col['NPI'] ?? -1] ?? '') ?: null,
                ];
            }
        }
        fclose($handle);
        if ($rows < 1) {
            return $this->failed($by, 'The OIG list was empty.');
        }

        return DB::transaction(function () use ($members, $matches, $rows, $listUpdated, $by) {
            $check = OigCheck::create(['run_by' => $by?->id, 'list_updated_at' => $listUpdated, 'list_rows' => $rows, 'members_checked' => $members->count()]);
            foreach (User::byLastName($members) as $member) {
                $found = $matches[$member->id] ?? [];
                $dob = $found ? self::memberDob($member) : null;
                foreach ($found as &$m) {
                    $m['same_dob'] = $dob && $m['dob'] ? $dob === $m['dob'] : null;
                }
                unset($m);
                $status = match (true) {
                    ! $found => 'clear',
                    collect($found)->containsStrict('same_dob', true) => 'excluded',
                    collect($found)->containsStrict('same_dob', null) => 'review',
                    default => 'clear', // same name, different date of birth: a different person
                };
                $check->results()->create([
                    'user_id' => $member->id,
                    'name' => $member->name,
                    'dob_masked' => self::maskedDob($member),
                    'status' => $status,
                    'matches' => $found ?: null,
                ]);
            }
            $check->recount();

            return $check;
        });
    }

    /** The first name and every way the last name could be written, as comparison keys. */
    public static function nameKeys(string $name): array
    {
        $tokens = array_values(array_filter(preg_split('/\s+/', strtoupper(trim($name))), fn ($t) => ! in_array(self::key($t), self::SUFFIXES, true) && self::key($t) !== ''));
        $first = self::key($tokens[0] ?? '');
        $lasts = [];
        // "Maria De La Cruz" could be listed as CRUZ, LA CRUZ or DE LA CRUZ.
        for ($i = 1; $i < count($tokens); $i++) {
            $lasts[] = self::key(implode('', array_slice($tokens, $i)));
        }

        return [$first, array_values(array_unique($lasts ?: [$first]))];
    }

    public static function key(string $value): string
    {
        return preg_replace('/[^A-Z]/', '', strtoupper($value));
    }

    /** YYYYMMDD from the OIG file as Y-m-d, or null for blank or 00000000. */
    private static function date(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    private static function memberDob(User $member): ?string
    {
        try {
            return $member->dob();
        } catch (Throwable) {
            return null;
        }
    }

    private static function maskedDob(User $member): ?string
    {
        try {
            return $member->dobMasked() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function failed(?User $by, string $error): OigCheck
    {
        return OigCheck::create(['run_by' => $by?->id, 'status' => 'failed', 'error' => $error]);
    }
}
