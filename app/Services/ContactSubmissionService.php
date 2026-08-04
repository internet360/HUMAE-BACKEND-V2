<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContactSubmission;
use App\Notifications\NewContactSubmissionNotification;
use Illuminate\Support\Facades\Notification;

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

        Notification::route('mail', $address)
            ->notify(new NewContactSubmissionNotification($submission));
    }
}
