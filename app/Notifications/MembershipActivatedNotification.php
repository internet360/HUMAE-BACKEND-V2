<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Membership;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MembershipActivatedNotification extends Notification
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

        return (new MailMessage)
            ->subject('Tu membresía HUMAE está activa')
            ->greeting('¡Bienvenido a HUMAE!')
            ->line('Tu membresía de candidato se activó correctamente.')
            ->line("Vigencia hasta: {$expires}")
            ->action(
                'Completar mi perfil',
                rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/').'/me/profile',
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'membership.activated',
            'membership_id' => $this->membership->id,
            'expires_at' => $this->membership->expires_at?->toIso8601String(),
            'title' => 'Membresía activada',
            'body' => 'Tu membresía HUMAE está activa.',
        ];
    }
}
