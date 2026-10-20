<?php

namespace App\Notifications;

use App\Models\DrawCredit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DrawExpiringSoon extends Notification
{
    public function __construct(public DrawCredit $credit) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Draw Box draw expires soon')
            ->greeting("Hi {$notifiable->name},")
            ->line("You still have a draw from the call at {$this->credit->call->location}.")
            ->line('It expires '.$this->credit->expires_at->format('l, M j').'.')
            ->action('Draw now', route('home'));
    }
}
