<?php

namespace App\Notifications;

use App\Models\DrawCredit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DrawCredited extends Notification
{
    public function __construct(public DrawCredit $credit) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You earned a draw from the Draw Box')
            ->greeting("Nice work, {$notifiable->name}!")
            ->line("Your crew's call at {$this->credit->call->location} earned you a draw.")
            ->line('Draw by '.$this->credit->expires_at->format('l, M j').' or it expires.')
            ->action('Draw now', route('home'));
    }
}
