<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\InterviewRequestCandidate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa a la empresa que HUMAE no va a presentarle a uno de los perfiles que
 * señaló, y por qué.
 *
 * El motivo viaja completo. Un veto sin explicación deja al cliente rehaciendo
 * la selección a ciegas, y probablemente eligiendo a alguien con el mismo
 * impedimento.
 *
 * El perfil se nombra por su referencia opaca, no por su nombre: que HUMAE lo
 * descarte no es motivo para revelar quién era. La regla de identidad no
 * depende del desenlace.
 */
class InterviewRequestCandidateVetoedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly InterviewRequestCandidate $item,
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
        $this->item->loadMissing(['candidateProfile', 'interviewRequest.vacancy']);

        $reference = $this->displayCode();
        $title = $this->item->interviewRequest->vacancy->title ?? 'tu vacante';

        return (new MailMessage)
            ->subject('Un perfil de tu solicitud no estará disponible')
            ->greeting('Hola')
            ->line("No vamos a poder presentarte al perfil {$reference} para {$title}.")
            ->line('Motivo: '.($this->item->rejection_reason ?? 'sin especificar'))
            ->line('El resto de tu solicitud sigue su curso; no necesitas volver a enviarla.')
            ->action(
                'Ver la solicitud',
                rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/').'/me/empresa/solicitudes',
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->item->loadMissing(['candidateProfile', 'interviewRequest']);

        return [
            'type' => 'interview_request.candidate_vetoed',
            'interview_request_id' => $this->item->interview_request_id,
            'candidate_reference' => $this->item->candidateProfile->public_reference ?? null,
            'candidate_display_code' => $this->displayCode(),
            'reason' => $this->item->rejection_reason,
            'title' => 'Un perfil no estará disponible',
            'body' => 'HUMAE no presentará el perfil '.$this->displayCode().'.',
        ];
    }

    private function displayCode(): string
    {
        $reference = (string) ($this->item->candidateProfile->public_reference ?? '');

        return strtoupper(substr(str_replace('-', '', $reference), 0, 6));
    }
}
