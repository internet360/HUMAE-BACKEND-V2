<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa al usuario que su solicitud de cuenta fue rechazada. Incluye
 * un motivo opcional capturado por el admin.
 */
class AccountRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
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
        $mail = (new MailMessage)
            ->subject('No pudimos aprobar tu cuenta HUMAE')
            ->greeting('Hola,')
            ->line('Revisamos tu solicitud y, por ahora, no podemos aprobarla.');

        if ($this->reason !== null && trim($this->reason) !== '') {
            $mail->line('Motivo: '.$this->reason);
        }

        return $mail
            ->line('Si crees que es un error o quieres más información, escríbenos a soporte@humae.com.mx.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'account_rejected',
            'reason' => $this->reason,
        ];
    }
}
