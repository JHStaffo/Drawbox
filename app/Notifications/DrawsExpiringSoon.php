<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** One email covering every draw an employee has that expires within a day. */
class DrawsExpiringSoon extends Notification
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
            ->subject($count === 1 ? 'Your Draw Box draw expires tomorrow' : "{$count} of your Draw Box draws expire tomorrow")
            ->greeting("Hi {$notifiable->name},")
            ->line($count === 1 ? 'You have a draw that expires within 24 hours:' : "You have {$count} draws that expire within 24 hours:");

        foreach ($this->credits->sortBy('expires_at') as $credit) {
            $mail->line("- {$credit->call->location}, expires ".$credit->expires_at->format('D M j, g:i A'));
        }

        return $mail->action('Draw now', route('home'));
    }
}
