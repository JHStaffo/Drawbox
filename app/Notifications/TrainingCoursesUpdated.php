<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** One email a day listing the courses a Team Member finished that have been updated since. */
class TrainingCoursesUpdated extends Notification
{
    /** @param  Collection<int, \App\Training\Models\Assignment>  $assignments */
    public function __construct(public Collection $assignments) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->assignments->count();
        $mail = (new MailMessage)
            ->subject($count === 1 ? 'Training update: '.$this->assignments->first()->course->title : "{$count} of your training courses were updated")
            ->greeting("Hi {$notifiable->name},")
            ->line($count === 1 ? 'A course you finished has been updated. Please look over what changed.' : 'Courses you finished have been updated. Please look over what changed.');
        foreach ($this->assignments as $a) {
            $versions = $a->course->versions->where('number', '>', $a->completed_version)->sortBy('number');
            $what = $versions->map(fn ($v) => trim($v->note ?: $v->summary()))->filter()->implode(' ');
            $mail->line("**{$a->course->title}**".($what ? ": {$what}" : ''));
        }
        $mail->line('Your completion still counts.');

        return $count === 1
            ? $mail->action('Open the course', route('training.course', $this->assignments->first()))
            : $mail->action('Open My Training', route('training'));
    }
}
