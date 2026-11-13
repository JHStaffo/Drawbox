<?php

namespace App\Wellness;

use App\Models\Setting;
use App\Models\User;
use App\Wellness\Models\Point;
use App\Wellness\Models\Rule;
use App\Wellness\Models\Submission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Visits, InBody scans and weigh-ins: submitted by the Team Member, checked
 * by a reviewer, and scored within each activity's cap (10 a week for
 * self-care, 370 a year for the dentist, and so on).
 */
class Submissions
{
    /** Allowed note types, up to 10 MB. Phone photos (HEIC) are fine. */
    public const NOTE_RULE = 'file|max:10240|mimes:jpg,jpeg,png,heic,heif,pdf';

    public function __construct(private Wellness $wellness) {}

    /** The activities a Team Member can submit, from the rules in effect today. */
    public static function activities(): \Illuminate\Support\Collection
    {
        return Rule::current()->reject(fn (Rule $r) => $r->isDaily());
    }

    /** Weigh-ins are the Team Member's own confirmation, like the meal plan, so they score straight away. */
    public static function selfConfirmed(string $activity): bool
    {
        return $activity === 'weigh_in';
    }

    public static function needsUpload(Rule $rule): bool
    {
        return $rule->needs_note || $rule->key === 'inbody';
    }

    /**
     * @param  array{body_fat_pct?: mixed, muscle_lb?: mixed}|null  $measures
     */
    public function submit(User $user, string $activity, string $day, ?UploadedFile $note = null, ?array $measures = null, ?string $comment = null, ?Submission $fixing = null): Submission
    {
        if (! $this->wellness->participant($user)?->isActive()) {
            throw ValidationException::withMessages(['activity' => 'Sign up for Wellness first.']);
        }
        $rule = self::activities()->get($activity);
        if (! $rule) {
            throw ValidationException::withMessages(['activity' => 'Pick an activity.']);
        }
        $date = Calendar::date($day);
        $oldest = Calendar::today()->subDays((int) Setting::get('wellness_submit_days'));
        if ($date->gt(Calendar::today())) {
            throw ValidationException::withMessages(['day' => "The date can't be in the future."]);
        }
        if ($date->lt($oldest)) {
            throw ValidationException::withMessages(['day' => 'That is too long ago. Activities can be submitted up to '.Setting::get('wellness_submit_days').' days after they happen.']);
        }
        if (self::needsUpload($rule) && ! $note) {
            throw ValidationException::withMessages(['note' => $rule->key === 'inbody'
                ? 'Attach a photo or PDF of the InBody printout.'
                : 'Attach a photo or PDF of the signed attendance note.']);
        }
        if ($rule->key === 'inbody') {
            $measures = $this->cleanMeasures($measures ?? []);
        } else {
            $measures = null;
        }
        $dup = Submission::where('user_id', $user->id)->where('activity', $activity)->where('day', $date->toDateString())
            ->whereIn('status', ['pending', 'approved'])->exists();
        if ($dup) {
            throw ValidationException::withMessages(['day' => "You've already sent a {$rule->name} for that date."]);
        }
        if ($fixing && ($fixing->user_id !== $user->id || $fixing->status !== 'rejected')) {
            $fixing = null;
        }

        $submission = DB::transaction(function () use ($user, $rule, $date, $note, $measures, $comment, $fixing) {
            $submission = Submission::create([
                'user_id' => $user->id, 'activity' => $rule->key, 'day' => $date->toDateString(), 'status' => 'pending',
                'measures' => $measures, 'comment' => $comment ? mb_substr(trim($comment), 0, 500) : null,
                'resubmission_of' => $fixing?->id,
            ]);
            if ($note) {
                $this->storeNote($submission, $note);
            }

            return $submission;
        });

        if (self::selfConfirmed($rule->key)) {
            $this->approve($submission, null);
        }

        return $submission->fresh();
    }

    /** Body fat % and skeletal muscle mass (lb), the two measures the InBody rule compares. */
    private function cleanMeasures(array $measures): array
    {
        $errors = [];
        $fat = $measures['body_fat_pct'] ?? null;
        $muscle = $measures['muscle_lb'] ?? null;
        if (! is_numeric($fat) || $fat < 2 || $fat > 75) {
            $errors['body_fat_pct'] = 'Enter the body fat percentage from the printout (2 to 75).';
        }
        if (! is_numeric($muscle) || $muscle < 20 || $muscle > 250) {
            $errors['muscle_lb'] = 'Enter the skeletal muscle mass in pounds from the printout (20 to 250).';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['body_fat_pct' => round((float) $fat, 1), 'muscle_lb' => round((float) $muscle, 1)];
    }

    /** Encrypts the file and keeps it outside the web folder. */
    private function storeNote(Submission $submission, UploadedFile $note): void
    {
        $path = 'wellness/notes/'.$submission->user_id.'/'.$submission->id.'-'.Str::random(16).'.enc';
        Storage::disk('local')->put($path, Crypt::encrypt(file_get_contents($note->getRealPath())));
        $submission->update([
            'note_path' => $path,
            'note_name' => mb_substr($note->getClientOriginalName() ?: 'note', 0, 200),
            'note_mime' => $note->getMimeType(),
        ]);
    }

    /** The decrypted note, for the owner or a reviewer. */
    public function noteContents(Submission $submission): ?string
    {
        if (! $submission->note_path || ! Storage::disk('local')->exists($submission->note_path)) {
            return null;
        }

        return Crypt::decrypt(Storage::disk('local')->get($submission->note_path));
    }

    /**
     * Approve and score it within its cap. Returns the points given, which
     * can be 0 when the cap is used up or an InBody scan didn't improve.
     */
    public function approve(Submission $submission, ?User $reviewer): int
    {
        if ($submission->status !== 'pending') {
            throw ValidationException::withMessages(['submission' => 'That was already '.strtolower($submission->statusLabel()).'.']);
        }
        if ($reviewer && $reviewer->id === $submission->user_id) {
            throw ValidationException::withMessages(['submission' => "You can't approve your own submission. Another reviewer has to."]);
        }

        return DB::transaction(function () use ($submission, $reviewer) {
            $rule = Rule::forDay($submission->day)->get($submission->activity);
            [$points, $note] = $this->pointsFor($submission, $rule);
            $point = $points > 0 ? Point::create([
                'user_id' => $submission->user_id, 'day' => $submission->day, 'rule_id' => $rule->id,
                'activity' => $rule->key, 'category' => 'other', 'points' => $points, 'kind' => 'earned',
                'reason' => "Submission #{$submission->id}",
            ]) : null;
            $submission->update([
                'status' => 'approved', 'rule_id' => $rule->id, 'points' => $points, 'points_note' => $note,
                'point_id' => $point?->id, 'reviewed_by' => $reviewer?->id, 'reviewed_at' => now(),
            ]);

            return $points;
        });
    }

    /** @return array{0: int, 1: ?string} points and why it's less than the rule's points, if it is */
    private function pointsFor(Submission $submission, Rule $rule): array
    {
        if ($rule->key === 'inbody') {
            $qualifies = $this->inbodyImproved($submission);
            if ($qualifies !== true) {
                return [0, $qualifies];
            }
        }
        $points = $rule->points;
        if ($rule->cap_points !== null) {
            [$from, $to] = Calendar::period($rule->cap_period ?? 'year', $submission->day);
            $already = (int) Point::where('user_id', $submission->user_id)->where('activity', $rule->key)->where('category', 'other')->where('kind', 'earned')
                ->where('day', '>=', $from->toDateString())->where('day', '<=', $to->toDateString())->sum('points');
            $left = max(0, $rule->cap_points - $already);
            if ($left < $points) {
                return [$left, $left === 0
                    ? "The {$rule->name} limit of {$rule->cap_points} points a {$rule->cap_period} is already reached."
                    : "Only {$left} points were left under the {$rule->cap_points} a {$rule->cap_period} limit."];
            }
        }

        return [$points, null];
    }

    /**
     * True when this scan improved enough on the last approved one at least
     * the minimum days earlier; otherwise the reason it scores 0.
     */
    private function inbodyImproved(Submission $submission): true|string
    {
        $minDays = (int) Setting::get('wellness_inbody_min_days');
        $before = Calendar::date($submission->day)->subDays($minDays)->toDateString();
        $previous = Submission::where('user_id', $submission->user_id)->where('activity', 'inbody')->where('status', 'approved')
            ->where('day', '<=', $before)->orderByDesc('day')->orderByDesc('id')->first();
        if (! $previous) {
            return "First scan: it's the starting point that later scans are compared with.";
        }
        $now = $submission->measures;
        $then = $previous->measures;
        $fatDrop = round($then['body_fat_pct'] - $now['body_fat_pct'], 1);
        $muscleGain = round($now['muscle_lb'] - $then['muscle_lb'], 1);
        if ($fatDrop >= (float) Setting::get('wellness_inbody_fat_drop') || $muscleGain >= (float) Setting::get('wellness_inbody_muscle_gain')) {
            return true;
        }

        return 'No qualifying improvement on the '.$previous->dayLabel().' scan (needs body fat down '.Setting::get('wellness_inbody_fat_drop')
            .' points or muscle up '.Setting::get('wellness_inbody_muscle_gain').' lb).';
    }

    public function reject(Submission $submission, User $reviewer, string $reason): void
    {
        if ($submission->status !== 'pending') {
            throw ValidationException::withMessages(['submission' => 'That was already '.strtolower($submission->statusLabel()).'.']);
        }
        if ($reviewer->id === $submission->user_id) {
            throw ValidationException::withMessages(['submission' => "You can't review your own submission. Another reviewer has to."]);
        }
        $submission->update(['status' => 'rejected', 'reject_reason' => $reason, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
    }

    public function withdraw(Submission $submission): void
    {
        if ($submission->status !== 'pending') {
            throw ValidationException::withMessages(['submission' => 'Only something still waiting for review can be withdrawn.']);
        }
        $submission->update(['status' => 'withdrawn']);
    }

    /** "Dentist visit on Dec 3, 2026" for messages and the audit log. */
    public static function describe(Submission $submission): string
    {
        $name = Rule::where('key', $submission->activity)->value('name') ?? $submission->activity;

        return "{$name} on ".$submission->dayLabel();
    }
}
