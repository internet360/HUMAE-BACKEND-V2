<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Interview;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InterviewScheduledNotification extends Notification
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
            ->subject('Nueva entrevista propuesta')
            ->greeting('Hola')
            ->line('Se propuso una nueva entrevista para ti.')
            ->line("Fecha: {$scheduled}")
            ->line('Confirma tu asistencia desde tu panel.')
            ->action('Ver entrevista', rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/').'/me/entrevistas');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'interview.scheduled',
            'interview_id' => $this->interview->id,
            'vacancy_assignment_id' => $this->interview->vacancy_assignment_id,
            'scheduled_at' => $this->interview->scheduled_at?->toIso8601String(),
            'title' => $this->interview->title ?? 'Entrevista HUMAE',
            'body' => 'Se propuso una nueva entrevista para ti.',
        ];
    }
}
