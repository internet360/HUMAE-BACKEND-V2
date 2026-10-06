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
        $e = self::escape(...);

        $mail = (new MailMessage)
            ->subject("Nueva solicitud de factura #{$request->id}")
            ->greeting('Hola equipo de facturación,')
            ->line(sprintf('%s (%s) solicitó una factura.', $e($user?->name), $e($user?->email)))
            ->line('Datos fiscales:')
            ->line("RFC: {$request->rfc}")
            ->line("Razón social: {$e($request->legal_name)}")
            ->line("Régimen fiscal: {$request->tax_regime}")
            ->line("Código postal: {$request->postal_code}")
            ->line("Uso de CFDI: {$request->cfdi_use}")
            ->line("Correo para el CFDI: {$e($request->email)}")
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

    /**
     * Mail lines are rendered as Markdown, so user-supplied text must not be
     * able to open links, images, emphasis, code or raw HTML.
     *
     * Only Markdown-significant characters are backslash-escaped. `&`, `<` and
     * `>` are deliberately left alone: the mail template already HTML-escapes
     * every line (`{{ $line }}`), so a backslash in front of the resulting
     * entity would render it as literal text (`&amp;amp;`), and raw HTML can
     * never reach the Markdown parser anyway.
     */
    private static function escape(?string $value): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]()#!|~])/u', '\\\\$1', (string) $value);
    }
}
