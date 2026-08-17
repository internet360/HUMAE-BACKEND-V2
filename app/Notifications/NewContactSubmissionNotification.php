<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ContactSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa a HUMAE que llegó un mensaje por el formulario público de contacto
 * (landing, /contacto o /empresas). Se envía a la dirección de soporte
 * configurada, no a un usuario del sistema: quien escribe no necesariamente
 * tiene cuenta. Ver `humae_docs/notificaciones/catalogo-eventos.md`
 * («Ticket de contacto»).
 *
 * Va a la cola (`QUEUE_CONNECTION=database` + `queue:work` por cron en
 * producción) y no dentro del request: el visitante no tiene por qué esperar
 * a que el SMTP conteste, y un correo rechazado se reintenta en el worker
 * (`--tries=3`) en vez de morir en el único intento que había.
 *
 * `ShouldQueue` es lo que encola: el trait `Queueable` sólo aporta los
 * setters de cola/retraso. Sin la interfaz, el envío es SÍNCRONO — que es
 * exactamente cómo un «550 No Such User Here» tumbó el endpoint público el
 * 14-08-2026.
 */
class NewContactSubmissionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ContactSubmission $submission,
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
        $mail = (new MailMessage)
            ->subject('Nuevo mensaje de contacto: '.($this->submission->subject ?? $this->typeLabel()))
            ->replyTo($this->submission->email, $this->submission->name)
            ->greeting('Hola equipo,')
            ->line(sprintf(
                '%s (%s) escribió a través de %s.',
                $this->submission->name,
                $this->submission->email,
                $this->submission->source ?? 'el sitio web',
            ));

        if ($this->submission->company !== null) {
            $mail->line('Empresa: '.$this->submission->company);
        }

        if ($this->submission->phone !== null) {
            $mail->line('Teléfono: '.$this->submission->phone);
        }

        return $mail
            ->line('Mensaje:')
            ->line($this->submission->message)
            ->line('Responde directamente a este correo o desde el panel de administración.');
    }

    private function typeLabel(): string
    {
        return match ($this->submission->type) {
            'company_request' => 'Solicitud de empresa',
            'support' => 'Soporte',
            default => 'Contacto',
        };
    }
}
