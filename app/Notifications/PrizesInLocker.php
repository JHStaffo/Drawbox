<?php

namespace App\Notifications;

use App\Models\LockerAssignment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PrizesInLocker extends Notification
{
    public function __construct(public LockerAssignment $assignment) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->assignment;
        $mail = (new MailMessage)
            ->subject("Your Draw Box prizes are in locker {$a->locker->number}")
            ->greeting("Hi {$notifiable->name},")
            ->line("You have prizes waiting in locker {$a->locker->number} at the {$a->locker->officeName()} office. Use combination {$a->combination} to open it.");

        foreach ($a->draws as $draw) {
            $mail->line('- '.$draw->label());
        }

        return $mail
            ->line('Please pick them up by '.$a->due_at->format('l, M j').'. After that, prizes still in the locker are forfeited.')
            ->line('When you have them, press Picked Up on your My draws page.')
            ->action('Open My draws', route('home'));
    }
}
