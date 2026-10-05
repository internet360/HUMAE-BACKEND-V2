<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\InvoiceRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the billing mailbox a candidate asked for a CFDI.
 *
 * Deliberately NOT queued: `NotifyBillingOfInvoiceRequestJob` is the queued
 * unit and sends it with `notifyNow()` so it can stamp `billing_notified_at`
 * only when the mail was really handed over. Contains the RFC (the billing
 * team needs it); nothing here may ever be logged.
 */
class InvoiceRequestedNotification extends Notification
{
    public function __construct(public readonly InvoiceRequest $request) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request->loadMissing(['user', 'payments.payment']);
        $user = $request->user;

        $mail = (new MailMessage)
            ->subject("Nueva solicitud de factura #{$request->id}")
            ->greeting('Hola equipo de facturación,')
            ->line(sprintf('%s (%s) solicitó una factura.', $user?->name, $user?->email))
            ->line('Datos fiscales:')
            ->line("RFC: {$request->rfc}")
            ->line("Razón social: {$request->legal_name}")
            ->line("Régimen fiscal: {$request->tax_regime}")
            ->line("Código postal: {$request->postal_code}")
            ->line("Uso de CFDI: {$request->cfdi_use}")
            ->line("Correo para el CFDI: {$request->email}")
            ->line('Pagos a facturar:');

        foreach ($request->payments as $line) {
            $text = sprintf(
                '- $%s MXN, pagado el %s',
                number_format((float) $line->amount, 2),
                $line->paid_at->setTimezone((string) config('billing.timezone'))->format('d/m/Y H:i'),
            );

            $receipt = $line->payment->receipt_url ?? null;
            $mail->line($receipt !== null ? "{$text}. Recibo de Stripe: {$receipt}" : $text);
        }

        $link = rtrim((string) config('app.frontend_url'), '/')."/admin/facturacion/{$request->id}";

        return $mail->action('Abrir solicitud', $link)->line("Solicitud: {$link}");
    }
}
