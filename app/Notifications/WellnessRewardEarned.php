<?php

namespace App\Notifications;

use App\Wellness\Models\Award;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WellnessRewardEarned extends Notification
{
    public function __construct(public Award $award) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('You earned a '.money($this->award->value_cents).' Wellness gift card')
            ->greeting("Nice work, {$notifiable->name}!")
            ->line('You earned all '.$this->award->points.' daily-habit points for the week of '.$this->award->period_start->format('F j').' to '.$this->award->period_end->format('F j').'.');
        if ($this->award->test_period) {
            $mail->line('This week was in the Wellness test period, so the reward is up to the program administrators.');
        }

        return $mail->line('Pick which gift card you would like on your Activities and rewards page.')
            ->action('Pick my gift card', route('wellness.activities'));
    }
}
