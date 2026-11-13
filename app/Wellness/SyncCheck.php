<?php

namespace App\Wellness;

use App\Notifications\WellnessSyncStopped;
use App\Wellness\Models\Participant;
use App\Wellness\Models\Reading;
use Carbon\CarbonImmutable;

/**
 * Emails Team Members whose wearable has stopped sending data. Only people
 * who have synced at least once are checked, and each gap gets one email:
 * the next one waits until a sync comes in and stops again.
 */
class SyncCheck
{
    /** A device sync older than this many hours counts as stopped. */
    public const STALE_HOURS = 48;

    public const DEVICE_SOURCES = ['apple_health', 'fitbit'];

    /** @return int how many Team Members were emailed */
    public function run(): int
    {
        if (! Wellness::enabled()) {
            return 0;
        }
        $cutoff = now()->subHours(self::STALE_HOURS);
        $last = Reading::whereIn('source', self::DEVICE_SOURCES)
            ->selectRaw('user_id, MAX(updated_at) as last')->groupBy('user_id')->pluck('last', 'user_id');

        $sent = 0;
        Participant::with('user')->where('status', 'active')->whereIn('user_id', $last->keys())->get()
            ->each(function (Participant $p) use ($last, $cutoff, &$sent) {
                $lastSync = CarbonImmutable::parse($last[$p->user_id]);
                if ($lastSync->gt($cutoff) || ! $p->user?->active) {
                    return;
                }
                if ($p->sync_notice_at && $p->sync_notice_at->gte($lastSync)) {
                    return; // already told about this gap
                }
                if ($p->hasDeviceException(Calendar::today()->toDateString())) {
                    return; // an admin already knows the device isn't working
                }
                $source = Reading::whereIn('source', self::DEVICE_SOURCES)->where('user_id', $p->user_id)->latest('updated_at')->value('source');
                $p->user->notify(new WellnessSyncStopped($lastSync, $source));
                $p->update(['sync_notice_at' => now()]);
                $sent++;
            });

        return $sent;
    }
}
