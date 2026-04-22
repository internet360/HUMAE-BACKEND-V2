<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly string $roleLabel,
        public readonly ?string $inviterName = null,
        public readonly ?string $companyName = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');
        $url = $frontend.'/onboard?token='.$this->token;

        $mail = (new MailMessage)
            ->subject('Te invitamos a HUMAE')
            ->greeting('¡Hola!')
            ->line($this->inviterName !== null
                ? "{$this->inviterName} te invitó a HUMAE como {$this->roleLabel}."
                : "Has sido invitado a HUMAE como {$this->roleLabel}.");

        if ($this->companyName !== null) {
            $mail->line("Estarás vinculado a la empresa {$this->companyName}.");
        }

        return $mail
            ->line('Haz clic en el botón para elegir tu contraseña y activar la cuenta.')
            ->action('Activar mi cuenta', $url)
            ->line('El enlace caduca en 7 días. Si no fuiste tú, ignora este correo.');
    }
}
