<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AssignmentStage;
use App\Models\VacancyAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssignmentStageChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly VacancyAssignment $assignment,
        public readonly AssignmentStage $stage,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'pipeline.stage_changed',
            'vacancy_assignment_id' => $this->assignment->id,
            'vacancy_id' => $this->assignment->vacancy_id,
            'stage' => $this->stage->value,
            'title' => 'Avance en el proceso',
            'body' => $this->stageMessage(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Actualización en tu proceso HUMAE')
            ->greeting('Hola')
            ->line($this->stageMessage())
            ->action(
                'Ver mi panel',
                rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/').'/dashboard',
            );
    }

    private function stageMessage(): string
    {
        return match ($this->stage) {
            AssignmentStage::Presented => 'Tu perfil fue presentado a una empresa.',
            AssignmentStage::Interviewing => 'Pasaste a la fase de entrevistas.',
            AssignmentStage::Finalist => '¡Fuiste seleccionado como finalista!',
            AssignmentStage::Hired => '¡Felicidades! Fuiste contratado.',
            AssignmentStage::Rejected => 'El proceso no avanzó en esta ocasión.',
            AssignmentStage::Withdrawn => 'Tu postulación fue retirada.',
            default => 'Hubo una actualización en tu proceso.',
        };
    }
}
