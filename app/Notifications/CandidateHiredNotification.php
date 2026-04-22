<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\VacancyAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Se dispara al mover una asignación a `hired`. Va al candidato contratado
 * y a los company_user (owners/managers) de la empresa.
 */
class CandidateHiredNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly VacancyAssignment $assignment,
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
            'type' => 'pipeline.candidate_hired',
            'vacancy_assignment_id' => $this->assignment->id,
            'vacancy_id' => $this->assignment->vacancy_id,
            'vacancy_title' => $this->assignment->vacancy?->title,
            'title' => '¡Proceso cerrado con éxito!',
            'body' => $this->message(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('¡Proceso cerrado! – HUMAE')
            ->greeting('Hola')
            ->line($this->message())
            ->action(
                'Ver detalle',
                rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/').'/dashboard',
            );
    }

    private function message(): string
    {
        $vacancyTitle = $this->assignment->vacancy->title ?? 'la vacante';
        $candidate = $this->assignment->candidateProfile;
        $candidateName = $candidate
            ? trim($candidate->first_name.' '.$candidate->last_name)
            : 'el candidato';

        return "Se cerró el proceso de {$vacancyTitle} con la contratación de {$candidateName}.";
    }
}
