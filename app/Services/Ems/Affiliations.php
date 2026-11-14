<?php

namespace App\Services\Ems;

use App\Models\AuditLog;
use App\Models\EmsAffiliation;
use App\Models\EmsCheck;
use App\Models\EmsCheckResult;
use App\Models\EmsUnacceptableAgency;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\EmsCheckNeedsAttention;
use App\Services\OigExclusions;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Searches every active Team Member on the Virginia EMS provider search and
 * keeps track of the agencies they're listed with:
 *  - each should be listed with our agency (Setting ems_our_agency),
 *  - any other agency is kept with when it was first and last seen; a new one
 *    waits for a review (acceptable or unacceptable), unless it's on the
 *    unacceptable agencies list, which flags it without a review,
 *  - a Team Member marked "accepted exclusion" is still searched every time,
 *    and someone is told the first time they turn up.
 * Searching by name fills in the certification # once one provider at our
 * agency matches, and later checks search by that number.
 */
class Affiliations
{
    public function __construct(private ProviderSearch $search) {}

    /** Start a check: it runs from the scheduler (`ems:run`) within a minute. */
    public function queue(?User $by): EmsCheck
    {
        return EmsCheck::create(['run_by' => $by?->id, 'status' => 'queued', 'members_total' => User::where('active', true)->count()]);
    }

    public function run(EmsCheck $check): EmsCheck
    {
        $members = User::byLastName(User::where('active', true)->get());
        $check->update(['status' => 'running', 'started_at' => now(), 'members_total' => $members->count(), 'members_done' => 0]);
        $ours = Agency::key(Setting::get('ems_our_agency'));
        $unacceptable = EmsUnacceptableAgency::pluck('agency_key')->flip();
        $failedInARow = 0;
        $newlyFound = [];

        foreach ($members as $i => $member) {
            if ($i > 0 && ($pause = (int) config('services.va_ems.pause_ms')) > 0) {
                usleep($pause * 1000);
            }
            $result = $this->checkMember($check, $member, $ours, $unacceptable);
            if ($result->exclusion_found && ! $member->ems_exclusion_found_at) {
                $member->forceFill(['ems_exclusion_found_at' => now()])->saveQuietly();
                $newlyFound[] = $member->name;
            }
            $failedInARow = $result->status === 'failed' ? $failedInARow + 1 : 0;
            $check->update(['members_done' => $i + 1]);
            // The site is down or has changed: stop rather than fail everyone.
            if ($failedInARow >= 3 && $i + 1 === $failedInARow) {
                $check->update(['status' => 'failed', 'error' => $result->error, 'finished_at' => now()]);

                return $check;
            }
        }

        $check->recount();
        $check->update(['status' => 'done', 'finished_at' => now()]);
        try {
            $this->notify($check->fresh(), $newlyFound);
        } catch (Throwable $e) {
            report($e); // the check is saved either way
        }

        return $check;
    }

    private function checkMember(EmsCheck $check, User $member, string $ours, $unacceptable): EmsCheckResult
    {
        $base = [
            'user_id' => $member->id,
            'name' => $member->name,
            'searched_by' => $member->ems_cert ? 'cert' : 'name',
            'accepted_exclusion' => (bool) $member->ems_exclusion_at,
        ];
        [$first, $lasts] = OigExclusions::nameKeys($member->name);
        $words = preg_split('/\s+/', trim($member->name));

        try {
            $providers = $member->ems_cert
                ? $this->search->search(null, null, $member->ems_cert)
                : $this->search->search($words[0] ?? '', self::lastName($member->name), null);
        } catch (SearchFailed $e) {
            return $check->results()->create($base + ['status' => 'failed', 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return $check->results()->create($base + ['status' => 'failed', 'error' => "Couldn't search the provider site."]);
        }

        $matches = $member->ems_cert
            ? array_values(array_filter($providers, fn ($p) => ! $p['cert'] || self::sameCert($p['cert'], $member->ems_cert)))
            : array_values(array_filter($providers, fn ($p) => self::sameName($p, $first, $lasts)));
        if (count($matches) > 1) {
            // Two people with the same name: the one listed with us is ours.
            $withUs = array_values(array_filter($matches, fn ($p) => collect($p['agencies'])->contains(fn ($a) => Agency::key($a) === $ours)));
            if (count($withUs) === 1) {
                $matches = $withUs;
            }
        }

        if (! $matches) {
            $this->markGone($member, null);

            return $check->results()->create($base + ['status' => 'not_found']);
        }
        if (count($matches) > 1) {
            return $check->results()->create($base + ['status' => 'multiple', 'providers' => array_map(fn ($p) => array_intersect_key($p, array_flip(['name', 'cert', 'level', 'expires', 'agencies'])), $matches)]);
        }

        $provider = $matches[0];
        $hasOurs = false;
        $seen = [];
        $new = [];
        foreach ($provider['agencies'] as $agency) {
            $key = Agency::key($agency);
            if ($key === $ours) {
                $hasOurs = true;

                continue;
            }
            $seen[] = $key;
            $link = EmsAffiliation::firstOrNew(['user_id' => $member->id, 'agency_key' => $key]);
            if (! $link->exists) {
                $link->fill(['agency' => $agency, 'first_seen_at' => now(), 'status' => 'pending']);
                $new[] = $agency;
            }
            if ($link->status === 'pending' && $unacceptable->has($key)) {
                $link->fill(['status' => 'unacceptable', 'auto_flagged' => true]);
            }
            $link->fill(['last_seen_at' => now(), 'gone_at' => null])->save();
        }
        $this->markGone($member, $seen);

        if ($hasOurs && ! $member->ems_cert && $provider['cert']) {
            $member->forceFill(['ems_cert' => mb_substr($provider['cert'], 0, 30)])->saveQuietly();
            AuditLog::record('system.ems-check', "Saved Virginia EMS certification # {$provider['cert']} for {$member->name} from the provider search (listed with our agency).");
        }

        $links = EmsAffiliation::where('user_id', $member->id)->whereIn('agency_key', $seen)->get();

        return $check->results()->create($base + [
            'status' => 'found',
            'cert' => $provider['cert'],
            'level' => $provider['level'],
            'expires' => $provider['expires'],
            'has_ours' => $hasOurs,
            'agencies' => $provider['agencies'],
            'new_agencies' => $new ?: null,
            'review_agencies' => $links->where('status', 'pending')->pluck('agency')->values()->all() ?: null,
            'unacceptable_agencies' => $links->where('status', 'unacceptable')->pluck('agency')->values()->all() ?: null,
            'exclusion_found' => (bool) $member->ems_exclusion_at,
        ]);
    }

    /** Agencies no longer listed for this Team Member (all of them when they weren't found). */
    private function markGone(User $member, ?array $stillListed): void
    {
        EmsAffiliation::where('user_id', $member->id)->whereNull('gone_at')
            ->when($stillListed !== null, fn ($q) => $q->whereNotIn('agency_key', $stillListed))
            ->update(['gone_at' => now()]);
    }

    /** Email everyone with the EMS Affiliations permission when a check found something. */
    private function notify(EmsCheck $check, array $newlyFound): void
    {
        $results = $check->results()->get();
        $lines = array_filter([
            $check->unacceptable_count ? "{$check->unacceptable_count} unacceptable ".str('affiliation')->plural($check->unacceptable_count) : null,
            $check->missing_ours_count ? "{$check->missing_ours_count} ".str('Team Member')->plural($check->missing_ours_count).' not listed with '.Setting::get('ems_our_agency') : null,
            ($n = $results->sum(fn ($r) => count($r->new_agencies ?? []))) ? "{$n} new ".str('affiliation')->plural($n).' to review' : null,
            $newlyFound ? 'Found on the search after being marked accepted exclusion: '.implode(', ', $newlyFound) : null,
            $check->not_found_count ? "{$check->not_found_count} ".str('Team Member')->plural($check->not_found_count).' not found' : null,
            $check->problem_count ? "{$check->problem_count} that couldn't be matched to one provider" : null,
        ]);
        if (! $lines) {
            return;
        }
        $to = User::where('active', true)->with('profile')->get()->filter(fn (User $u) => $u->hasPermission('ems_affiliations'));
        if ($to->isNotEmpty()) {
            Notification::send($to, new EmsCheckNeedsAttention($check, array_values($lines)));
        }
    }

    /** The last name to type in the search: the last word, leaving off Jr, Sr, II... */
    public static function lastName(string $name): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($name)), fn ($w) => ! in_array(OigExclusions::key($w), ['JR', 'SR', 'II', 'III', 'IV', 'V'], true)));

        return count($words) > 1 ? end($words) : ($words[0] ?? '');
    }

    /** The provider's name matches the Team Member's first and last name. */
    public static function sameName(array $provider, string $first, array $lasts): bool
    {
        if ($provider['last']) {
            $pFirst = OigExclusions::key(explode(' ', trim((string) $provider['first']))[0] ?? '');
            $pLasts = [OigExclusions::key($provider['last'])];
        } elseif (str_contains($provider['name'], ',')) {
            [$last, $rest] = array_map('trim', explode(',', $provider['name'], 2));
            $pFirst = OigExclusions::key(explode(' ', $rest)[0] ?? '');
            $pLasts = [OigExclusions::key($last)];
        } else {
            [$pFirst, $pLasts] = OigExclusions::nameKeys($provider['name']);
        }

        return $pFirst === $first && array_intersect($pLasts, $lasts) !== [];
    }

    public static function sameCert(string $a, string $b): bool
    {
        $norm = fn ($c) => ltrim(preg_replace('/[^A-Z0-9]/', '', strtoupper($c)), '0');

        return $norm($a) === $norm($b);
    }
}
