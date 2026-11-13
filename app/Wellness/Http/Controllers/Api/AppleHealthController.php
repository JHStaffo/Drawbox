<?php

namespace App\Wellness\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Wellness\Calendar;
use App\Wellness\Models\Reading;
use App\Wellness\Scoring;
use App\Wellness\Wellness;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The endpoint the Apple Health Shortcut calls. It takes flat fields, the way
 * a Shortcut builds them, and is forgiving about how numbers are written
 * ("8,432", "7.5 hr", "7:30"). Each run sends a day's totals plus, with a
 * yesterday_ prefix, the day before, so a run the phone missed catches up the
 * next day. A blank or zero value is skipped, never saved over a real one.
 * The reply has a "message" the Shortcut shows as a notification.
 */
class AppleHealthController extends Controller
{
    /** Shortcut field => metric. sleep_hours is turned into minutes. */
    private const FIELDS = [
        'steps' => 'steps',
        'sleep_minutes' => 'sleep_minutes',
        'sleep_hours' => 'sleep_minutes',
        'exercise_minutes' => 'exercise_minutes',
        'water_oz' => 'water_oz',
    ];

    public function __construct(private Wellness $wellness) {}

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'day' => 'nullable|string|max:30',
            'device' => 'nullable|string|max:100',
        ]);

        $day = $this->parseDay($data['day'] ?? null);
        if ($day === null) {
            throw ValidationException::withMessages(['day' => 'Send the day as yyyy-MM-dd, "today" or "yesterday".']);
        }
        $device = trim((string) ($data['device'] ?? '')) ?: 'iPhone';

        $results = [];
        foreach (['yesterday_' => $day->subDay(), '' => $day] as $prefix => $date) {
            $values = $this->values($request, $prefix);
            if ($values) {
                $results[] = $this->save($user, $date->toDateString(), $values, $device);
            }
        }
        if (! $results) {
            return response()->json([
                'message' => 'Pulse: nothing to send. Apple Health had no steps, sleep or exercise for that day yet.',
                'days' => [],
            ]);
        }

        audit_note('Apple Health Shortcut sync: '.collect($results)->map(fn ($r) => $r['day'].' '.($r['saved'] ? ($r['changes'] ? implode(', ', $r['changes']) : 'no change') : 'skipped ('.$r['reason'].')'))->implode('; ').'.');

        return response()->json([
            'message' => $this->message(end($results), count($results) > 1 ? $results[0] : null),
            'days' => $results,
        ]);
    }

    /** "today", "yesterday", 2026-11-13, 11/13/2026 or a full date and time from a Shortcut. */
    private function parseDay(?string $value): ?\Carbon\CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '' || strcasecmp($value, 'today') === 0) {
            return Calendar::today();
        }
        if (strcasecmp($value, 'yesterday') === 0) {
            return Calendar::today()->subDay();
        }
        try {
            return Calendar::date($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The numbers sent for one day, as metric => value, leaving out blanks and zeros. */
    private function values(Request $request, string $prefix): array
    {
        $values = [];
        foreach (self::FIELDS as $field => $metric) {
            $number = $field === 'sleep_hours' ? self::hours($request->input($prefix.$field)) : self::number($request->input($prefix.$field));
            if ($number === null || $number <= 0) {
                continue;
            }
            $values[$metric] = round($number, 2);
        }

        return $values;
    }

    /** A number from text such as "8,432", "8432 steps" or "42.5 min". */
    public static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        if (! preg_match('/-?\d[\d,]*(\.\d+)?|-?\.\d+/', $value, $m)) {
            return null;
        }

        return (float) str_replace(',', '', $m[0]);
    }

    /** Hours from "7.5", "7.5 hr", "7:30" or "7 hr 30 min", returned as minutes. */
    public static function hours(mixed $value): ?float
    {
        if (is_string($value) && preg_match('/^\s*(\d+):(\d{1,2})/', $value, $m)) {
            return $m[1] * 60 + $m[2];
        }
        if (is_string($value) && preg_match('/(\d+(?:\.\d+)?)\s*h[a-z]*\s*(\d+)\s*m/i', $value, $m)) {
            return $m[1] * 60 + $m[2];
        }
        $hours = self::number($value);

        return $hours === null ? null : $hours * 60;
    }

    private function save($user, string $day, array $values, string $device): array
    {
        if (! Calendar::isOpen($day)) {
            return ['day' => $day, 'saved' => false, 'reason' => Calendar::date($day)->isFuture() ? 'that day hasn\'t happened yet' : 'that week is closed', 'values' => $values];
        }
        try {
            $result = $this->wellness->record($user, $day, $values, 'apple_health', $device);
        } catch (ValidationException $e) {
            return ['day' => $day, 'saved' => false, 'reason' => collect($e->errors())->flatten()->implode(' '), 'values' => $values];
        }

        return ['day' => $day, 'saved' => true, 'points' => $result['points'], 'changes' => $result['changes'], 'values' => $values];
    }

    /** One line for the iPhone notification, about the main day. */
    private function message(array $main, ?array $yesterday): string
    {
        if (! $main['saved']) {
            return "Pulse: {$main['day']} wasn't saved because {$main['reason']}.";
        }
        $parts = collect($main['values'])->map(fn ($v, $metric) => Wellness::show($metric, $v).($metric === 'steps' ? ' steps' : ($metric === 'sleep_minutes' ? ' sleep' : ($metric === 'exercise_minutes' ? ' exercise' : ' water'))))->implode(', ');
        $label = $main['day'] === Calendar::today()->toDateString() ? 'Today' : Calendar::date($main['day'])->format('D M j');
        $line = "Pulse: {$label} {$parts}. {$main['points']} of ".Scoring::dailyMax().' points so far.';
        if ($yesterday && ! $yesterday['saved'] && ! str_contains($yesterday['reason'], 'closed')) {
            $line .= " Yesterday wasn't saved: {$yesterday['reason']}.";
        }

        return $line;
    }
}
