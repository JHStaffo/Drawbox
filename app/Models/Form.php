<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A form team members fill out in Pulse. Each submission is saved and posted to the form's Slack channel. */
class Form extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('position')->orderBy('id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * Keeps $answer numbers in the Slack message pointing at the same
     * questions after they're reordered or one is removed.
     *
     * @param  array<int, int|null>  $map  old number => new number (null: removed)
     */
    public function renumberTemplate(array $map): void
    {
        if (! $this->slack_template) {
            return;
        }
        $this->update(['slack_template' => preg_replace_callback('/\$answer(\d+)\b/', function ($m) use ($map) {
            if (! array_key_exists((int) $m[1], $map)) {
                return $m[0];
            }

            return $map[(int) $m[1]] === null ? '' : '$answer'.$map[(int) $m[1]];
        }, $this->slack_template)]);
    }

    /** "#dispatch-log", or the channel ID if the name wasn't known when it was picked. */
    public function channelLabel(): ?string
    {
        if (! $this->slack_channel) {
            return null;
        }

        return $this->slack_channel_name ? '#'.$this->slack_channel_name : $this->slack_channel;
    }
}
