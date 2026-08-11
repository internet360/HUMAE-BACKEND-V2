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
     *
     * Se comparan días de CALENDARIO (`startOfDay` en ambos lados), no el
     * intervalo entre instantes. En Carbon 3 `diffInDays()` devuelve float y el
     * cast a int trunca: con el job corriendo a la 01:00 y una membresía que
     * vence pasado mañana a las 00:30, el intervalo es 2.97 días → decía
     * "vence en 2 días" cuando faltaban 3. Peor todavía en el borde: una que
     * vencía mañana imprimía "vence hoy", que es falso.
     *
     * `copy()` no es opcional: `startOfDay()` muta la instancia, y `expires_at`
     * es el atributo casteado del modelo — sin la copia le arrastraríamos la
     * hora a cero también a `translatedFormat()` y a `toArray()`.
     */
    private function deadlineLine(): string
    {
        $expiresAt = $this->membership->expires_at;

        if ($expiresAt === null) {
            return 'Tu membresía de candidato está por vencer.';
        }

        $daysLeft = (int) now()->startOfDay()->diffInDays(
            $expiresAt->copy()->startOfDay(),
            absolute: true,
        );

        if ($daysLeft <= 0) {
            return 'Tu membresía de candidato vence hoy.';
        }

        $unit = $daysLeft === 1 ? 'día' : 'días';

        return "Tu membresía de candidato vence en {$daysLeft} {$unit}.";
    }
}
