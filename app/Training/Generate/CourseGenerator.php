<?php

namespace App\Training\Generate;

use App\Models\AuditLog;
use App\Services\ProtocolImport\ReaderException;
use App\Training\Models\Course;
use App\Training\Models\Generation;
use App\Training\Models\Lesson;
use App\Training\Models\Question;
use App\Training\RichText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Runs AI course requests from the cron job (training:generate, every minute):
 * asks Claude for the course, then saves it as a draft course with designer
 * pages and a quiz. Nothing is assigned or published until someone reviews it.
 */
class CourseGenerator
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly CourseWriter $writer) {}

    public function ready(): bool
    {
        return $this->writer->ready();
    }

    /** Writes the oldest waiting course. Returns false when nothing is waiting. */
    public function runNext(): bool
    {
        // After a failed try, wait a little longer each time (2, 4, 6... minutes).
        $generation = Generation::whereIn('status', ['queued', 'working'])->orderBy('id')->get()
            ->first(fn (Generation $g) => ! $g->attempts || $g->updated_at->lte(now()->subMinutes(2 * $g->attempts)));
        if (! $generation) {
            return false;
        }
        $this->run($generation);

        return true;
    }

    public function run(Generation $generation): void
    {
        $generation->fill(['status' => 'working', 'started_at' => $generation->started_at ?? now()])->save();
        $source = $generation->source_path
            ? ['path' => Storage::disk('local')->path($generation->source_path), 'mime' => $generation->source_mime, 'name' => $generation->source_name]
            : null;

        try {
            $result = $this->writer->write($generation->brief + ['topic' => $generation->topic], $source);
        } catch (ReaderException $e) {
            $generation->attempts++;
            $generation->error = $e->getMessage();
            if (! $e->retry || $generation->attempts >= self::MAX_ATTEMPTS) {
                $generation->fill(['status' => 'failed', 'finished_at' => now()])->save();
                AuditLog::record('system.training-generate', "Stopped writing the AI course \"{$generation->topic}\": {$e->getMessage()}");
            } else {
                $generation->save();
            }

            return;
        }

        $course = $this->save($generation, $result['course']);
        $generation->fill([
            'status' => 'done',
            'course_id' => $course->id,
            'error' => null,
            'notes' => $result['notes'] ?: null,
            'input_tokens' => $generation->input_tokens + $result['input_tokens'],
            'output_tokens' => $generation->output_tokens + $result['output_tokens'],
            'finished_at' => now(),
        ])->save();
        AuditLog::record('system.training-generate', "Claude wrote the draft course {$course->title} for \"{$generation->topic}\": "
            .$course->lessons()->where('type', 'text')->count().' page(s) and '.Question::whereIn('lesson_id', $course->lessons()->pluck('id'))->count().' quiz question(s).');
    }

    /** Saves Claude's answer as a draft course. */
    public function save(Generation $generation, array $data): Course
    {
        return DB::transaction(function () use ($generation, $data) {
            $brief = $generation->brief;
            $course = Course::create([
                'title' => Str::limit(trim($data['title'] ?? '') ?: $generation->topic, 250, ''),
                'description' => Str::limit(trim($data['description'] ?? ''), 5000, '') ?: null,
                'category' => Str::limit(trim($data['category'] ?? ''), 80, '') ?: null,
                'status' => 'draft',
                'created_by' => $generation->requested_by,
                'updated_by' => $generation->requested_by,
            ]);
            $position = 0;
            if ($generation->source_path && ! empty($brief['add_source_lesson']) && $generation->source_mime === 'application/pdf') {
                $path = 'training/'.$course->id.'/source-'.Str::random(12).'.pdf';
                Storage::disk('local')->copy($generation->source_path, $path);
                $course->lessons()->create([
                    'title' => pathinfo($generation->source_name, PATHINFO_FILENAME) ?: 'Source document',
                    'type' => 'pdf', 'position' => ++$position, 'file_path' => $path, 'file_name' => $generation->source_name,
                    'file_mime' => 'application/pdf', 'file_size' => Storage::disk('local')->size($path),
                ]);
            }
            foreach ($data['lessons'] ?? [] as $lesson) {
                $html = RichText::clean($lesson['html'] ?? '');
                if ($html === '') {
                    continue;
                }
                $course->lessons()->create([
                    'title' => Str::limit(trim($lesson['title'] ?? '') ?: 'Lesson '.($position + 1), 250, ''),
                    'type' => 'text',
                    'position' => ++$position,
                    'body' => $html,
                ]);
            }

            $questions = collect($data['questions'] ?? [])->map(fn ($q) => $this->question($q))->filter()->values();
            if ($questions->isNotEmpty()) {
                $quiz = $course->lessons()->create([
                    'title' => Str::limit(trim($data['quiz_title'] ?? '') ?: 'Quiz', 250, ''),
                    'type' => 'quiz',
                    'position' => ++$position,
                    'passing_score' => $brief['passing_score'] ?? Lesson::DEFAULT_PASSING,
                    'show_answers' => true,
                ]);
                foreach ($questions as $i => $q) {
                    $quiz->questions()->create($q + ['position' => $i + 1]);
                }
            }

            return $course;
        });
    }

    /** One question from Claude's answer, checked the same way the builder checks them. Null when it doesn't hold up. */
    private function question(array $q): ?array
    {
        $type = $q['type'] ?? 'single';
        $prompt = trim($q['prompt'] ?? '');
        $choices = collect($q['choices'] ?? [])->map(fn ($c) => ['text' => Str::limit(trim($c['text'] ?? ''), 1000, ''), 'correct' => (bool) ($c['correct'] ?? false)])
            ->filter(fn ($c) => $c['text'] !== '')->take(10)->values()->all();
        if ($type === 'true_false') {
            $right = collect($choices)->firstWhere('correct', true)['text'] ?? null;
            $choices = [['text' => 'True', 'correct' => strcasecmp((string) $right, 'True') === 0], ['text' => 'False', 'correct' => strcasecmp((string) $right, 'False') === 0]];
        }
        $rightCount = count(array_filter($choices, fn ($c) => $c['correct']));
        if (! isset(Question::TYPES[$type]) || $prompt === '' || count($choices) < 2 || $rightCount === 0 || ($type !== 'multiple' && $rightCount > 1)) {
            return null;
        }

        return ['type' => $type, 'prompt' => Str::limit($prompt, 5000, ''), 'choices' => $choices, 'explanation' => trim($q['explanation'] ?? '') ?: null];
    }

    public static function deleteSource(Generation $generation): void
    {
        if ($generation->source_path) {
            Storage::disk('local')->delete($generation->source_path);
        }
    }
}
