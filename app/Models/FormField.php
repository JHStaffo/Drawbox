<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One question on a form. */
class FormField extends Model
{
    protected $guarded = [];

    public const TYPES = [
        'text' => 'Short answer',
        'textarea' => 'Paragraph',
        'number' => 'Number',
        'date' => 'Date',
        'time' => 'Time',
        'datetime' => 'Date and time',
        'select' => 'Dropdown (pick one)',
        'radio' => 'Multiple choice (pick one)',
        'checkboxes' => 'Checkboxes (pick any)',
        'yesno' => 'Yes / No',
        'team_member' => 'Team Member (pick one)',
        'email' => 'Email',
        'phone' => 'Phone',
    ];

    /** Types that need a list of choices. */
    public const WITH_OPTIONS = ['select', 'radio', 'checkboxes'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'allow_other' => 'boolean'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** @return list<string> the choices, one per line */
    public function choices(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $this->options)), 'strlen'));
    }

    public function needsChoices(): bool
    {
        return in_array($this->type, self::WITH_OPTIONS, true);
    }

    /** The value the "Other:" choice sends; the typed answer comes in otherInputName(). */
    public const OTHER = '__other';

    public function hasOther(): bool
    {
        return $this->allow_other && $this->needsChoices();
    }

    public function otherInputName(): string
    {
        return $this->inputName().'_other';
    }

    /** The input name on the fill-out page. */
    public function inputName(): string
    {
        return 'f'.$this->id;
    }
}
