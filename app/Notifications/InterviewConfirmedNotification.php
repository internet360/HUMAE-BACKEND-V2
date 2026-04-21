<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Interview;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InterviewConfirmedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Interview $interview,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $scheduled = $this->interview->scheduled_at?->translatedFormat('l d F Y, H:i');

        return (new MailMessage)
            ->subject('Entrevista confirmada')
            ->greeting('Hola')
            ->line("Se confirmó la entrevista del {$scheduled}.")
            ->action('Ver detalles', rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/').'/me/entrevistas');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'interview.confirmed',
            'interview_id' => $this->interview->id,
            'scheduled_at' => $this->interview->scheduled_at?->toIso8601String(),
            'title' => 'Entrevista confirmada',
            'body' => 'Se confirmó la entrevista agendada.',
        ];
    }
}
