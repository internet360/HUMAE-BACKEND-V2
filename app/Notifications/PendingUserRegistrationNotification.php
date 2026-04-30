<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa a un admin que un nuevo reclutador o empresa se registró públicamente
 * y queda pendiente de aprobación. Se manda a TODOS los admins del sistema.
 */
class PendingUserRegistrationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $applicantName,
        public readonly string $applicantEmail,
        public readonly string $roleLabel,
        public readonly ?string $companyName = null,
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
        $frontend = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');
        $url = $frontend.'/admin/usuarios?status=pending_approval';

        $mail = (new MailMessage)
            ->subject('Solicitud de registro pendiente: '.$this->roleLabel)
            ->greeting('Hola admin,')
            ->line(sprintf(
                '%s (%s) solicitó registrarse como %s.',
                $this->applicantName,
                $this->applicantEmail,
                $this->roleLabel,
            ));

        if ($this->companyName !== null) {
            $mail->line('Empresa: '.$this->companyName);
        }

        if ($this->reason !== null && $this->reason !== '') {
            $mail->line('Mensaje del solicitante: '.$this->reason);
        }

        return $mail
            ->line('Revisa la solicitud y aprueba o rechaza desde el panel de administración.')
            ->action('Ir a usuarios pendientes', $url)
            ->line('Mientras no lo apruebes, este usuario no podrá iniciar sesión.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'pending_user_registration',
            'applicant_name' => $this->applicantName,
            'applicant_email' => $this->applicantEmail,
            'role_label' => $this->roleLabel,
            'company_name' => $this->companyName,
            'reason' => $this->reason,
        ];
    }
}
