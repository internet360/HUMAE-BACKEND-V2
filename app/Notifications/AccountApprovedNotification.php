<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirma al usuario que su cuenta fue aprobada por un admin y ya puede
 * iniciar sesión.
 */
class AccountApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $roleLabel,
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
        $url = $frontend.'/login';

        return (new MailMessage)
            ->subject('Tu cuenta HUMAE fue aprobada')
            ->greeting('¡Hola!')
            ->line(sprintf(
                'Tu cuenta de %s en HUMAE fue aprobada. Ya puedes iniciar sesión.',
                $this->roleLabel,
            ))
            ->action('Iniciar sesión', $url)
            ->line('Si tienes dudas, escríbenos a soporte@humae.com.mx.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'account_approved',
            'role_label' => $this->roleLabel,
        ];
    }
}
