<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Membership;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de "tu membresía ya expiró". Se envía una sola vez, después de que
 * `expireStale()` marcó la membresía como `expired`; el candado vive en
 * `memberships.expired_notice_sent_at`.
 */
class MembershipExpiredNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Membership $membership,
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
        $expires = $this->membership->expires_at?->translatedFormat('d F Y');

        $renewUrl = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/')
            .'/dashboard/membresia';

        $message = (new MailMessage)
            ->subject('Tu membresía HUMAE expiró')
            ->greeting('Hola')
            ->line('Tu membresía de candidato expiró.');

        if ($expires !== null) {
            $message->line("Venció el {$expires}.");
        }

        return $message
            ->line('Tu perfil dejó de aparecer en las búsquedas de nuestro equipo de reclutamiento. Puedes reactivarlo renovando tu membresía; tu información y tus documentos siguen guardados.')
            ->action('Renovar mi membresía', $renewUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'membership.expired',
            'membership_id' => $this->membership->id,
            'expires_at' => $this->membership->expires_at?->toIso8601String(),
            'title' => 'Tu membresía expiró',
            'body' => 'Tu perfil dejó de aparecer en las búsquedas. Renueva para reactivarlo.',
        ];
    }
}
