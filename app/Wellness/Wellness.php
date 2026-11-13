<?php

namespace App\Wellness;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Wellness\Models\Participant;
use App\Wellness\Models\Reading;
use App\Wellness\Models\Rule;
use App\Wellness\Models\Tester;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Joining, leaving and recording wellness data. The web pages and the API
 * both come through here, so they always follow the same rules.
 */
class Wellness
{
    public function __construct(private Scoring $scoring) {}

    public static function enabled(): bool
    {
        return Setting::get('wellness_enabled') === '1';
    }

    /** The version of the rules people agree to when they join: the newest rule date. */
    public static function rulesVersion(): string
    {
        $newest = Rule::max('effective_from');

        return $newest ? substr((string) $newest, 0, 10) : 'none';
    }

    /**
     * True when this person may sign up: their permission profile has
     * Wellness, or an admin added them individually as a tester.
     */
    public static function canUse(User $user): bool
    {
        return $user->hasPermission('wellness') || Tester::where('user_id', $user->id)->exists();
    }

    public function participant(User $user): ?Participant
    {
        return Participant::where('user_id', $user->id)->first();
    }

    public function join(User $user): Participant
    {
        $participant = $this->participant($user);
        $version = self::rulesVersion();
        if ($participant?->isActive()) {
            return $participant;
        }
        $participant = Participant::updateOrCreate(['user_id' => $user->id], [
            'joined_at' => $participant?->joined_at ?? now(),
            'consent_version' => $version,
            'consent_at' => now(),
            'status' => 'active',
            'withdrawn_at' => null,
        ]);
        audit_note("{$user->name} joined the wellness program and agreed to the rules dated {$version} and the privacy notice.");

        return $participant;
    }

    public function withdraw(User $user): void
    {
        $participant = $this->participant($user);
        if (! $participant?->isActive()) {
            return;
        }
        $participant->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        audit_note("{$user->name} left the wellness program. Points earned so far are kept.");
    }

    /** True when this person may type steps, sleep and workout in for the day. */
    public function canTypeDeviceData(Participant $participant, string $day): bool
    {
        return Setting::get('wellness_manual_device') === '1' || $participant->hasDeviceException($day);
    }

    /**
     * Save values for one day from one source and score the day again.
     * A blank value removes that source's value for the metric.
     *
     * @param  array<string, mixed>  $values  metric => value
     * @return array{points: int, changes: array<int, string>}
     */
    public function record(User $user, string $day, array $values, string $source, ?string $device = null, ?User $by = null, ?string $recordedAt = null): array
    {
        $participant = $this->participant($user);
        if (! $participant?->isActive()) {
            throw ValidationException::withMessages(['day' => 'Join the wellness program first.']);
        }
        $date = Calendar::date($day);
        $day = $date->toDateString();
        if ($date->gt(Calendar::today())) {
            throw ValidationException::withMessages(['day' => "You can't log a day that hasn't happened yet."]);
        }
        if (Calendar::isLocked($day)) {
            throw ValidationException::withMessages(['day' => 'That week is closed. Ask a wellness administrator if something is missing.']);
        }

        $errors = [];
        $clean = [];
        foreach ($values as $metric => $value) {
            if (! isset(Reading::METRICS[$metric])) {
                $errors[$metric] = "Unknown measure: {$metric}.";

                continue;
            }
            if ($value === null || $value === '') {
                $clean[$metric] = null;

                continue;
            }
            if (! is_numeric($value) || $value < 0 || $value > Reading::LIMITS[$metric]) {
                $errors[$metric] = Reading::METRICS[$metric][0].' must be a number from 0 to '.number_format(Reading::LIMITS[$metric]).'.';

                continue;
            }
            if ($source === 'manual' && in_array($metric, Reading::DEVICE_METRICS, true) && ! $this->canTypeDeviceData($participant, $day)) {
                $errors[$metric] = Reading::METRICS[$metric][0].' comes from your wearable. Ask a wellness administrator for an exception if your device isn\'t working.';

                continue;
            }
            $clean[$metric] = round((float) $value, 2);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $changes = [];
        DB::transaction(function () use ($user, $day, $clean, $source, $device, $by, $recordedAt, &$changes) {
            foreach ($clean as $metric => $value) {
                $existing = Reading::where('user_id', $user->id)->where('day', $day)->where('metric', $metric)->where('source', $source)->first();
                $old = $existing?->value;
                if ($value === null) {
                    $existing?->delete();
                } else {
                    Reading::updateOrCreate(
                        ['user_id' => $user->id, 'day' => $day, 'metric' => $metric, 'source' => $source],
                        ['value' => $value, 'device' => $device, 'recorded_at' => $recordedAt ?? now(), 'entered_by' => $by?->id],
                    );
                }
                if ($old !== $value) {
                    $changes[] = Reading::METRICS[$metric][0].' from '.self::show($metric, $old).' to '.self::show($metric, $value);
                }
            }
        });

        $points = $this->scoring->scoreDay($user, $day);

        return ['points' => $points, 'changes' => $changes];
    }

    /** A value the way people read it: "7.5 h" of sleep, "10,250" steps, "yes" for the meal plan. */
    public static function show(string $metric, ?float $value): string
    {
        if ($value === null) {
            return 'none';
        }

        return match ($metric) {
            'meal_plan' => $value >= 1 ? 'yes' : 'no',
            'sleep_minutes' => rtrim(rtrim(number_format($value / 60, 1), '0'), '.').' h',
            'exercise_minutes' => number_format($value).' min',
            'water_oz' => number_format($value).' oz',
            default => number_format($value),
        };
    }

    /** Write an audit entry from code that runs outside a web request (imports, the scheduler). */
    public static function audit(string $action, string $description, ?User $user = null): void
    {
        AuditLog::record($action, $description, $user);
    }
}
