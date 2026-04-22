<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\VacancyAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Se dispara cuando una asignación pasa a `withdrawn` o `rejected` de forma
 * automática al cerrarse una vacante con otro candidato. Va al candidato
 * afectado.
 */
class AssignmentRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly VacancyAssignment $assignment,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'pipeline.assignment_rejected',
            'vacancy_assignment_id' => $this->assignment->id,
            'vacancy_id' => $this->assignment->vacancy_id,
            'vacancy_title' => $this->assignment->vacancy?->title,
            'reason' => $this->reason,
            'title' => 'El proceso se cerró con otro candidato',
            'body' => $this->message(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Actualización en tu proceso – HUMAE')
            ->greeting('Hola')
            ->line($this->message())
            ->line('Sigue disponible en el directorio: seguiremos presentando tu perfil a las vacantes que encajen.')
            ->action(
                'Ver mi panel',
                rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/').'/dashboard',
            );
    }

    private function message(): string
    {
        $vacancyTitle = $this->assignment->vacancy->title ?? 'la vacante a la que te postulamos';

        return "El proceso de {$vacancyTitle} se cerró con otro candidato. Gracias por tu participación.";
    }
}
