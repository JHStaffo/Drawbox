<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** One email covering every draw a team member earned since the last batch. */
class DrawCredited extends Notification
{
    /** @param  Collection<int, \App\Models\DrawCredit>  $credits */
    public function __construct(public Collection $credits) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->credits->count();
        $mail = (new MailMessage)
            ->subject($count === 1 ? 'You earned a draw from the Draw Box' : "You earned {$count} draws from the Draw Box")
            ->greeting("Nice work, {$notifiable->name}!");

        if ($count === 1) {
            $credit = $this->credits->first();
            $mail->line("Your crew's call at {$credit->call->location} earned you a draw.")
                ->line('Draw by '.$credit->expires_at->format('l, M j').' or it expires.');
        } else {
            $mail->line("Your crew's calls earned you {$count} draws:");
            foreach ($this->credits->sortBy('expires_at') as $credit) {
                $mail->line("- {$credit->call->location}, draw by ".$credit->expires_at->format('l, M j'));
            }
        }

        return $mail->action('Draw now', route('home'));
    }
}
