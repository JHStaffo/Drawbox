<?php

namespace App\Notifications;

use App\Models\EmsCheck;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to everyone with EMS Affiliations when a check finds something to look at. */
class EmsCheckNeedsAttention extends Notification
{
    /** @param list<string> $lines */
    public function __construct(public EmsCheck $check, public array $lines) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('EMS affiliation check: '.$this->lines[0])
            ->greeting("Hi {$notifiable->name},")
            ->line('The Virginia EMS affiliation check on '.$this->check->created_at->format('m/d/Y').' found:');
        foreach ($this->lines as $line) {
            $mail->line('- '.$line);
        }

        return $mail->action('Open the check', route('admin.ems.show', $this->check));
    }
}
