<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContactSubmission;
use App\Notifications\NewContactSubmissionNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class ContactSubmissionService
{
    /**
     * Persists a public contact/lead submission and notifies HUMAE support.
     *
     * @param  array{name: string, email: string, phone?: string|null, company?: string|null, subject?: string|null, message: string, type?: string|null, source?: string|null}  $data
     */
    public function submit(array $data, ?string $ip, ?string $userAgent): ContactSubmission
    {
        $submission = ContactSubmission::create([
            'type' => $data['type'] ?? 'contact',
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'subject' => $data['subject'] ?? null,
            'message' => $data['message'],
            'source' => $data['source'] ?? null,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);

        $this->notifySupport($submission);

        return $submission;
    }

    private function notifySupport(ContactSubmission $submission): void
    {
        $address = (string) config('mail.reply_to', '');

        // No hay dirección de soporte configurada (`.env` sin `MAIL_REPLY_TO`):
        // se guarda el lead de todos modos, sólo no hay a quién avisar por
        // correo. Queda visible igual en GET /admin/contact-submissions.
        if ($address === '') {
            return;
        }

        // El lead ya quedó persistido antes de llegar acá, así que avisar es
        // best-effort. Un SMTP caído, una cuota llena o un buzón de soporte
        // inexistente (550 No Such User Here) no pueden tumbar la captura: el
        // visitante vería un 500 sobre un lead que SÍ se guardó y reenviaría
        // el formulario, duplicándolo. El error queda en el log para que el
        // correo roto se arregle sin costarnos leads mientras tanto.
        try {
            Notification::route('mail', $address)
                ->notify(new NewContactSubmissionNotification($submission));
        } catch (Throwable $e) {
            Log::error('No se pudo avisar por correo de un lead de contacto.', [
                'submission_id' => $submission->id,
                'address' => $address,
                'exception' => $e,
            ]);
        }
    }
}
