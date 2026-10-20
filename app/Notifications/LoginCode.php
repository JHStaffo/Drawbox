<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginCode extends Notification
{
    public function __construct(public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your Draw Box sign-in code: {$this->code}")
            ->greeting("Hi {$notifiable->name},")
            ->line("Your sign-in code is **{$this->code}**.")
            ->line('It expires in 10 minutes. If you didn\'t try to sign in, you can ignore this email.');
    }
}
