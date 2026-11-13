<?php

namespace App\Training\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

/** One step of a course: something to read, watch or listen to, or a quiz. */
class Lesson extends Model
{
    protected $table = 'training_lessons';

    protected $guarded = [];

    public const TYPES = [
        'text' => 'Text page',
        'pdf' => 'PDF',
        'slides' => 'PowerPoint',
        'video' => 'Video',
        'audio' => 'Audio',
        'link' => 'Video link or web page',
        'file' => 'File to download',
        'quiz' => 'Quiz',
    ];

    /** Uploads each type takes (extensions). */
    public const FILE_TYPES = [
        'pdf' => ['pdf'],
        'slides' => ['ppt', 'pptx', 'pps', 'ppsx'],
        'video' => ['mp4', 'm4v', 'mov', 'webm'],
        'audio' => ['mp3', 'm4a', 'wav', 'aac', 'ogg'],
        'file' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'zip'],
    ];

    public const DEFAULT_PASSING = 80;

    protected static function booted(): void
    {
        // Keep the expected time in step with the content (see App\Training\Estimate).
        static::saving(function (Lesson $lesson) {
            $e = \App\Training\Estimate::lesson($lesson);
            $lesson->est_seconds = $e['seconds'];
            $lesson->est_basis = $e['basis'] ?: null;
            $lesson->search_text = $lesson->searchText();
        });
    }

    protected function casts(): array
    {
        return ['est_seconds' => 'integer', 'position' => 'integer', 'section_id' => 'integer', 'show_answers' => 'boolean', 'shuffle' => 'boolean', 'min_seconds' => 'integer', 'passing_score' => 'integer', 'max_attempts' => 'integer'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position')->orderBy('id');
    }

    /** What the reference search looks through: the title, the page text and a file's name. Quiz questions are left out. */
    public function searchText(): string
    {
        return trim($this->title.' '.\App\Training\RichText::text($this->body).' '.($this->file_name ? pathinfo($this->file_name, PATHINFO_FILENAME) : ''));
    }

    public function isQuiz(): bool
    {
        return $this->type === 'quiz';
    }

    public function hasFile(): bool
    {
        return isset(self::FILE_TYPES[$this->type]);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function passing(): int
    {
        return $this->passing_score ?? self::DEFAULT_PASSING;
    }

    /**
     * A link anyone holding it can open for two hours, without signing in. The
     * Office viewer needs it to fetch a PowerPoint, and phone apps use it to play files.
     */
    public function signedFileUrl(int $minutes = 120): ?string
    {
        return $this->file_path ? URL::temporarySignedRoute('training.file.public', now()->addMinutes($minutes), ['lesson' => $this->id]) : null;
    }

    /** YouTube, Vimeo or Loom links as an address that plays inside the page, or null for anything else. */
    public function embedUrl(): ?string
    {
        $url = (string) $this->url;
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([\w-]{11})~', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1];
        }
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)(?:/(\w+))?~', $url, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1].(isset($m[2]) ? '?h='.$m[2] : '');
        }
        if (preg_match('~loom\.com/(?:share|embed)/(\w+)~', $url, $m)) {
            return 'https://www.loom.com/embed/'.$m[1];
        }

        return null;
    }

    public function fileSizeLabel(): string
    {
        $bytes = (int) $this->file_size;

        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, round($bytes / 1024)).' KB';
    }
}
