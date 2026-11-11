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

    /** "#dispatch-log", or the channel ID if the name wasn't known when it was picked. */
    public function channelLabel(): ?string
    {
        if (! $this->slack_channel) {
            return null;
        }

        return $this->slack_channel_name ? '#'.$this->slack_channel_name : $this->slack_channel;
    }
}
