<?php

namespace App\Notifications;

use App\Wellness\Models\Reading;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WellnessSyncStopped extends Notification
{
    public function __construct(public CarbonImmutable $lastSync, public ?string $source) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your wearable hasn\'t synced with Pulse Wellness')
            ->greeting("Hi {$notifiable->name},")
            ->line('We haven\'t heard from your '.(Reading::SOURCES[$this->source] ?? 'wearable').' since '.$this->lastSync->format('l, F j \a\t g:i A').'. Steps, sleep and workouts only count when they sync.');

        if ($this->source === 'apple_health') {
            $mail->line('A few things to check on your iPhone:')
                ->line('1. Open Shortcuts, then Automation, and make sure the Fast Track Pulse automation is on and set to Run Immediately.')
                ->line('2. Run the Fast Track Pulse shortcut once by hand. It shows a message saying what it sent.')
                ->line('3. In the Health app, check that your Apple Watch is still adding Steps, Sleep and Exercise Minutes.')
                ->line('4. If the shortcut says your key doesn\'t work, make a new sync key on the Devices page and paste it into the shortcut.');
        } else {
            $mail->line('Open your device\'s app and check that it is syncing, then look at the Devices page in Pulse.');
        }

        return $mail->line('Days stay open for a few days after each week ends, so syncing soon still counts.')
            ->action('Open Devices', route('wellness.connect'))
            ->line('If your device is broken or lost, ask a wellness administrator for an exception so you can type your numbers in.');
    }
}
