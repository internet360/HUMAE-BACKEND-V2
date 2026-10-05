<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\InvoiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the requester their CFDI is ready. The files are NOT attached: the
 * user downloads them in the app, behind authentication. Only the validated
 * UUID and the request id are interpolated, so there is no user-supplied
 * Markdown to escape.
 */
class InvoiceIssuedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly InvoiceRequest $request)
    {
        $this->afterCommit = true;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $link = rtrim((string) config('app.frontend_url'), '/').'/dashboard/membresia/facturacion';

        $mail = (new MailMessage)
            ->subject('Tu factura ya está disponible')
            ->greeting('Hola,')
            ->line("Emitimos la factura de tu solicitud #{$this->request->id}.");

        if ($this->request->cfdi_uuid !== null) {
            $mail->line("Folio fiscal (UUID): {$this->request->cfdi_uuid}");
        }

        return $mail
            ->line('Puedes descargar el PDF y el XML desde tu cuenta, en la sección de facturación.')
            ->action('Ver mis facturas', $link);
    }
}
