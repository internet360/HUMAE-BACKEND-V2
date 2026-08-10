<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Membership;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de "tu membresía está por vencer". Se envía una sola vez, dentro de
 * la ventana previa a la expiración; el candado vive en
 * `memberships.expiry_warning_sent_at`.
 */
class MembershipExpiringNotification extends Notification
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
        $expiresAt = $this->membership->expires_at;
        $expires = $expiresAt?->translatedFormat('d F Y');

        $renewUrl = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/')
            .'/dashboard/membresia';

        $message = (new MailMessage)
            ->subject('Tu membresía HUMAE está por vencer')
            ->greeting('Hola')
            ->line($this->deadlineLine());

        if ($expires !== null) {
            $message->line("Fecha de vencimiento: {$expires}");
        }

        return $message
            ->line('Cuando vence, tu perfil deja de aparecer en las búsquedas de nuestro equipo de reclutamiento y pierdes acceso a tu expediente.')
            ->action('Renovar mi membresía', $renewUrl)
            ->line('Si ya renovaste, puedes ignorar este correo.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'membership.expiring',
            'membership_id' => $this->membership->id,
            'expires_at' => $this->membership->expires_at?->toIso8601String(),
            'title' => 'Tu membresía está por vencer',
            'body' => $this->deadlineLine(),
        ];
    }

    /**
     * Frase del plazo restante.
     *
     * Se separa del cuerpo del correo porque la reusa la notificación en app.
     * El caso `<= 0` existe de verdad: la ventana llega hasta el mismo día de
     * vencimiento, y ahí "vence en 0 días" sería una redacción rota.
     */
    private function deadlineLine(): string
    {
        $expiresAt = $this->membership->expires_at;

        if ($expiresAt === null) {
            return 'Tu membresía de candidato está por vencer.';
        }

        $daysLeft = (int) now()->diffInDays($expiresAt, absolute: true);

        if ($daysLeft <= 0) {
            return 'Tu membresía de candidato vence hoy.';
        }

        $unit = $daysLeft === 1 ? 'día' : 'días';

        return "Tu membresía de candidato vence en {$daysLeft} {$unit}.";
    }
}
