<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * An uploaded PDF or set of photos being turned into draft protocols.
 * The cron job reads it a few pages at a time (see ProtocolImporter).
 */
class ProtocolImport extends Model
{
    protected $guarded = [];

    public const STATUSES = [
        'queued' => 'Waiting to start',
        'working' => 'Reading',
        'done' => 'Done',
        'failed' => 'Stopped',
    ];

    protected function casts(): array
    {
        return [
            'files' => 'array',
            'protocol_ids' => 'array',
            'notes' => 'array',
            'pending_links' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return Collection<int, Protocol> */
    public function protocols(): Collection
    {
        return Protocol::with('latest')->whereIn('id', $this->protocol_ids ?? [])->get();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'working'], true);
    }

    /** "Pages 3 of 12" style progress, when the page count is known. */
    public function progress(): ?string
    {
        if ($this->kind !== 'pdf' || ! $this->page_count) {
            return null;
        }
        $done = min($this->page_count, $this->next_page - 1);

        return "{$done} of {$this->page_count} pages read";
    }

    /** Rough API cost in dollars at Claude Opus 5.5 prices ($4 in, $20 out per million tokens). */
    public function cost(): float
    {
        return round($this->input_tokens * 4 / 1_000_000 + $this->output_tokens * 20 / 1_000_000, 2);
    }

    public function addNote(string $note): void
    {
        $this->notes = [...($this->notes ?? []), $note];
    }
}
