<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\InterviewRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa al equipo de HUMAE que una empresa mandó una solicitud de entrevistas.
 *
 * Va al equipo, no al candidato: mientras HUMAE no acepte un perfil, la persona
 * no tiene por qué enterarse de que una empresa la señaló. Ese aviso llega
 * cuando hay algo real que proponerle.
 */
class InterviewRequestSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly InterviewRequest $interviewRequest,
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
        $this->interviewRequest->loadMissing(['company', 'vacancy']);

        $company = $this->companyName();
        $title = $this->interviewRequest->vacancy->title ?? 'una vacante';
        $count = $this->interviewRequest->candidates()->count();

        return (new MailMessage)
            ->subject('Nueva solicitud de entrevistas')
            ->greeting('Hola')
            ->line("{$company} envió una solicitud para {$title}.")
            ->line("Perfiles señalados: {$count}.")
            ->line('Propuso dos horarios para la primera entrevista.')
            ->action(
                'Ver solicitud',
                rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/').'/recruiter/solicitudes',
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->interviewRequest->loadMissing(['company', 'vacancy']);

        return [
            'type' => 'interview_request.submitted',
            'interview_request_id' => $this->interviewRequest->id,
            'vacancy_id' => $this->interviewRequest->vacancy_id,
            'company_id' => $this->interviewRequest->company_id,
            'candidates_count' => $this->interviewRequest->candidates()->count(),
            'title' => 'Nueva solicitud de entrevistas',
            'body' => $this->companyName().' envió una solicitud de entrevistas.',
        ];
    }

    /**
     * El nombre comercial si lo hay, la razón social si no. En un aviso interno
     * conviene el nombre por el que el equipo la conoce, no el fiscal.
     */
    private function companyName(): string
    {
        $company = $this->interviewRequest->company;

        return $company->trade_name
            ?? $company->legal_name
            ?? 'Una empresa cliente';
    }
}
