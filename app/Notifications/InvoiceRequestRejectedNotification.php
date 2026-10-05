<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\InvoiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the requester their invoice request was rejected (by an admin or
 * automatically after a reversed payment), why, and whether the payments can
 * be requested again. Never carries the RFC or other fiscal data.
 */
class InvoiceRequestRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $requestable  payments of the request that can be invoiced again
     * @param  int  $total  payments the request covered
     */
    public function __construct(
        public readonly InvoiceRequest $request,
        public readonly int $requestable,
        public readonly int $total,
    ) {
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
        $reason = trim((string) $this->request->rejection_reason);

        $mail = (new MailMessage)
            ->subject('Tu solicitud de factura fue rechazada')
            ->greeting('Hola,')
            ->line("No pudimos procesar tu solicitud de factura #{$this->request->id}.");

        if ($reason !== '') {
            $mail->line('Motivo: '.self::escape($reason));
        }

        return $mail
            ->line($this->availability())
            ->action('Ir a facturación', $link);
    }

    private function availability(): string
    {
        if ($this->requestable === 0) {
            return $this->total === 1
                ? 'El pago de esta solicitud no se puede volver a solicitar para factura desde tu cuenta.'
                : 'Los pagos de esta solicitud no se pueden volver a solicitar para factura desde tu cuenta.';
        }

        if ($this->requestable < $this->total) {
            return 'Los demás pagos de esta solicitud quedaron liberados: puedes volver a solicitar su factura desde tu cuenta, dentro del plazo de facturación. Los pagos restantes ya no se pueden solicitar.';
        }

        return $this->total === 1
            ? 'Tu pago quedó liberado: puedes volver a solicitar su factura desde tu cuenta, dentro del plazo de facturación.'
            : 'Tus pagos quedaron liberados: puedes volver a solicitar su factura desde tu cuenta, dentro del plazo de facturación.';
    }

    /**
     * Mail lines are rendered as Markdown, so user-supplied or admin-typed text
     * must not be able to open links, images, emphasis, code or raw HTML.
     */
    private static function escape(string $value): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]()<>#!|~&])/u', '\\\\$1', $value);
    }
}
