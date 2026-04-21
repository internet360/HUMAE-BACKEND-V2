<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Interview;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InterviewCancelledNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Interview $interview,
        public readonly ?string $reason = null,
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
        $message = (new MailMessage)
            ->subject('Entrevista cancelada')
            ->greeting('Hola')
            ->line('Se canceló una entrevista que tenías agendada.');

        if ($this->reason !== null && $this->reason !== '') {
            $message->line("Motivo: {$this->reason}");
        }

        return $message->action(
            'Ver entrevistas',
            rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/').'/me/entrevistas',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'interview.cancelled',
            'interview_id' => $this->interview->id,
            'reason' => $this->reason,
            'title' => 'Entrevista cancelada',
            'body' => 'Se canceló una entrevista que tenías agendada.',
        ];
    }
}
