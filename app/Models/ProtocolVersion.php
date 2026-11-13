<?php

namespace App\Models;

use App\Support\ProtocolText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a protocol: its title, flowchart and supplementary
 * sections. Once approved it is never edited again; changes go in a new
 * version, so the book always shows exactly what the Medical Director signed.
 */
class ProtocolVersion extends Model
{
    protected $guarded = [];

    /** States where the version can still change. */
    public const OPEN = ['draft', 'submitted', 'returned'];

    public const STATUSES = [
        'draft' => 'Draft',
        'submitted' => 'Waiting for approval',
        'returned' => 'Sent back',
        'approved' => 'Approved',
        'superseded' => 'Replaced',
    ];

    public const AGES = ['infant' => 'Infant', 'pediatric' => 'Pediatric', 'adult' => 'Adult'];

    /** Flowchart box types. */
    public const BOX_TYPES = [
        'action' => 'Action (red)',
        'step' => 'Step (white)',
        'decision' => 'Yes/no question (diamond)',
        'reference' => 'Treatment per another protocol (blue)',
        'note' => 'Note (yellow)',
        'exit' => 'Exit to another protocol (green)',
        'terminal' => 'Transport / end (black)',
    ];

    /** Level badges a box or section can carry, in the order they stack. */
    public const BADGES = [
        'E' => 'EMT',
        'A' => 'Advanced EMT',
        'I' => 'Intermediate',
        'P' => 'Paramedic',
        'peds' => 'Pediatric',
        'M' => 'Medical Control',
    ];

    /** Section colors (the band down the left side). */
    public const COLORS = [
        'purple' => '#7030a0',
        'green' => '#70ad47',
        'black' => '#111111',
        'pink' => '#e94fd1',
        'blue' => '#0076a3',
        'red' => '#ed1c24',
        'orange' => '#ed7d31',
        'navy' => '#2c3e50',
    ];

    /** Headings offered for new sections, with the color each usually gets. */
    public const SECTION_PRESETS = [
        'Goals' => 'purple',
        'Treatment' => 'green',
        'Special Considerations' => 'black',
        'Pediatrics' => 'pink',
    ];

    protected function casts(): array
    {
        return [
            'ages' => 'array',
            'chart' => 'array',
            'sections' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProtocolCategory::class, 'protocol_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'returned'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** "C-1 Cardiac Arrest" or just the title. */
    public function fullTitle(): string
    {
        return trim(($this->number ? $this->number.' ' : '').$this->title);
    }

    public function agesLabel(): string
    {
        return collect(self::AGES)->only($this->ages ?? [])->implode(', ') ?: 'All ages';
    }

    /** @return list<array> */
    public function boxes(): array
    {
        return $this->chart['nodes'] ?? [];
    }

    /** @return list<array> */
    public function arrows(): array
    {
        return $this->chart['edges'] ?? [];
    }

    /** Everything worth searching, as plain text. */
    public function buildSearchText(): string
    {
        $parts = [$this->number, $this->title, $this->category_name, $this->agesLabel()];
        foreach ($this->boxes() as $box) {
            $parts[] = ProtocolText::plain($box['text'] ?? '');
        }
        foreach ($this->sections ?? [] as $section) {
            $parts[] = $section['heading'] ?? '';
            $parts[] = ProtocolText::plain($section['text'] ?? '');
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts))));
    }

    /**
     * The same content the web page shows, for the future mobile apps.
     * Text keeps its light formatting (**bold**, __underline__, "- " bullets).
     */
    public function toApi(): array
    {
        $links = Protocol::inBook()->pluck('id')->all();

        return [
            'id' => $this->protocol_id,
            'version' => $this->version,
            'number' => $this->number,
            'title' => $this->title,
            'category' => $this->category_name,
            'ages' => array_values($this->ages ?? []),
            'chart' => [
                'boxes' => array_map(fn ($b) => [
                    'id' => $b['id'],
                    'type' => $b['type'],
                    'text' => $b['text'],
                    'badges' => $b['badges'] ?? [],
                    // Only links to protocols in the book; anything else would be a dead end.
                    'links_to' => in_array($b['link'] ?? null, $links, true) ? $b['link'] : null,
                ], $this->boxes()),
                'arrows' => array_map(fn ($e) => [
                    'from' => $e['from'], 'to' => $e['to'], 'label' => $e['label'] ?? null, 'style' => $e['style'] ?? 'one',
                ], $this->arrows()),
            ],
            'sections' => array_map(fn ($s) => [
                'heading' => $s['heading'], 'color' => self::COLORS[$s['color']] ?? null, 'badges' => $s['badges'] ?? [], 'text' => $s['text'],
            ], $this->sections ?? []),
            'revised' => $this->revised_label,
            'approved_at' => $this->reviewed_at?->toIso8601String(),
            'approved_by' => $this->signature,
        ];
    }
}
