<?php

declare(strict_types=1);

use App\Models\ContactSubmission;
use App\Notifications\NewContactSubmissionNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config(['mail.reply_to' => 'soporte@humae.test']);
});

it('accepts a public contact submission and notifies support', function (): void {
    Notification::fake();

    $response = $this->postJson('/api/v1/contact-submissions', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@empresa.test',
        'phone' => '+52 55 1234 5678',
        'company' => 'Empresa de sondeo S.A. de C.V.',
        'subject' => 'Quiero contratar candidatos',
        'message' => 'Nos interesa conocer más sobre el servicio de HUMAE.',
        'type' => 'company_request',
        'source' => 'empresas',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonMissingPath('data.id')
        ->assertJsonPath('data', null);

    $submission = ContactSubmission::where('email', 'ada@empresa.test')->firstOrFail();

    expect($submission->name)->toBe('Ada Lovelace')
        ->and($submission->type)->toBe('company_request')
        ->and($submission->source)->toBe('empresas')
        ->and($submission->company)->toBe('Empresa de sondeo S.A. de C.V.')
        ->and($submission->status)->toBe('new')
        ->and($submission->ip_address)->not->toBeNull();

    Notification::assertSentOnDemand(
        NewContactSubmissionNotification::class,
        fn (NewContactSubmissionNotification $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'soporte@humae.test'
            && $notification->submission->is($submission),
    );
});

it('defaults type to contact when omitted', function (): void {
    $this->postJson('/api/v1/contact-submissions', [
        'name' => 'Grace Hopper',
        'email' => 'grace@example.test',
        'message' => 'Quiero saber más sobre la plataforma.',
    ])->assertCreated();

    $submission = ContactSubmission::where('email', 'grace@example.test')->firstOrFail();

    expect($submission->type)->toBe('contact')
        ->and($submission->source)->toBeNull();
});

it('rejects a submission missing the required fields', function (): void {
    $response = $this->postJson('/api/v1/contact-submissions', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'message']);

    // CLAUDE.md: los mensajes deben venir en español neutro, no la llave cruda.
    $message = $response->json('errors.name.0');
    expect($message)->toBeString()->and($message)->not->toContain('validation.');
});

it('rejects an invalid email', function (): void {
    $this->postJson('/api/v1/contact-submissions', [
        'name' => 'Alguien',
        'email' => 'no-es-un-correo',
        'message' => 'Mensaje de prueba.',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('rejects a type outside the documented set', function (): void {
    $this->postJson('/api/v1/contact-submissions', [
        'name' => 'Alguien',
        'email' => 'alguien@example.test',
        'message' => 'Mensaje de prueba.',
        'type' => 'not-a-real-type',
    ])->assertStatus(422)->assertJsonValidationErrors(['type']);
});

it('does not echo the stored submission back', function (): void {
    $response = $this->postJson('/api/v1/contact-submissions', [
        'name' => 'Alguien',
        'email' => 'alguien@example.test',
        'message' => 'Mensaje de prueba que no debe reflejarse.',
    ]);

    $response->assertCreated();
    expect($response->getContent())->not->toContain('Mensaje de prueba que no debe reflejarse.');
});

it('throttles repeated public submissions from the same client', function (): void {
    $payload = fn (int $i) => [
        'name' => 'Sondeo '.$i,
        'email' => "sondeo{$i}@example.test",
        'message' => 'Mensaje de sondeo de throttle.',
    ];

    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/contact-submissions', $payload($i))->assertCreated();
    }

    $this->postJson('/api/v1/contact-submissions', $payload(6))
        ->assertStatus(429)
        ->assertJsonPath('success', false);
});
