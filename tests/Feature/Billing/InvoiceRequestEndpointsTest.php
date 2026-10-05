<?php

declare(strict_types=1);

use App\Enums\InvoiceRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\NotifyBillingOfInvoiceRequestJob;
use App\Models\InvoiceRequest;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\InvoiceRequestedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

const ENDPOINT_RFC = 'XXXX010101AAA';

function validInvoicePayload(array $paymentIds, array $override = []): array
{
    return [
        'rfc' => ENDPOINT_RFC,
        'legal_name' => 'Persona de Prueba',
        'tax_regime' => '612',
        'postal_code' => '06600',
        'cfdi_use' => 'G03',
        'email' => 'fiscal@example.com',
        'payment_ids' => $paymentIds,
        ...$override,
    ];
}

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'America/Mexico_City'));
    config(['billing.email' => 'facturacion@humae.test', 'app.frontend_url' => 'https://app.humae.test']);

    $this->user = User::factory()->create(['name' => 'Ana Candidata'])->assignRole(UserRole::Candidate->value);
    $this->payment = Payment::factory()->create(['user_id' => $this->user->id, 'paid_at' => now()->subDay()]);
    Sanctum::actingAs($this->user);
});

describe('GET /me/invoice-requests/eligible-payments', function (): void {
    it('lists only the own eligible payments', function (): void {
        Payment::factory()->create(['user_id' => User::factory(), 'paid_at' => now()]);
        Payment::factory()->create(['user_id' => $this->user->id, 'status' => PaymentStatus::Refunded, 'refunded_at' => now(), 'paid_at' => now()]);
        Payment::factory()->create(['user_id' => $this->user->id, 'paid_at' => now()->subMonths(2)]);

        $this->getJson('/api/v1/me/invoice-requests/eligible-payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->payment->id);
    });
});

describe('GET /me/invoice-requests/eligible-payments authorization', function (): void {
    it('is limited to candidates', function (UserRole $role): void {
        Sanctum::actingAs(User::factory()->create()->assignRole($role->value));

        $this->getJson('/api/v1/me/invoice-requests/eligible-payments')->assertForbidden();
    })->with([UserRole::Recruiter, UserRole::CompanyUser, UserRole::Admin]);
});

describe('POST /me/invoice-requests', function (): void {
    beforeEach(function (): void {
        Bus::fake();
    });

    it('creates a requested invoice request and queues the billing notification', function (): void {
        $this->postJson('/api/v1/me/invoice-requests', validInvoicePayload([$this->payment->id]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.status_label', 'Solicitada')
            ->assertJsonPath('data.rfc', ENDPOINT_RFC)
            ->assertJsonPath('data.payments.0.payment_id', $this->payment->id);

        $request = InvoiceRequest::firstOrFail();
        expect($request->user_id)->toBe($this->user->id)
            ->and($request->status)->toBe(InvoiceRequestStatus::Requested)
            ->and($request->billing_notified_at)->toBeNull();

        Bus::assertDispatched(NotifyBillingOfInvoiceRequestJob::class, fn ($job) => $job->invoiceRequestId === $request->id);
    });

    it('accepts compatible régimen, uso and person type combinations', function (array $override): void {
        $this->postJson('/api/v1/me/invoice-requests', validInvoicePayload([$this->payment->id], $override))
            ->assertCreated();
    })->with([
        'moral 601 + G03' => [['rfc' => 'ABC010101AAA', 'tax_regime' => '601', 'cfdi_use' => 'G03']],
        'fisica 605 + D01' => [['tax_regime' => '605', 'cfdi_use' => 'D01']],
        'fisica 616 + S01' => [['tax_regime' => '616', 'cfdi_use' => 'S01']],
        '626 on a moral RFC' => [['rfc' => 'ABC010101AAA', 'tax_regime' => '626', 'cfdi_use' => 'G01']],
        '626 on a fisica RFC' => [['tax_regime' => '626', 'cfdi_use' => 'G01']],
    ]);

    it('normalises the RFC to uppercase', function (): void {
        $this->postJson('/api/v1/me/invoice-requests', validInvoicePayload([$this->payment->id], ['rfc' => ' xxxx010101aaa ']))
            ->assertCreated();

        expect(InvoiceRequest::firstOrFail()->rfc)->toBe(ENDPOINT_RFC);
    });

    it('rejects invalid fiscal data with field errors and persists nothing', function (array $override, string $field): void {
        $this->postJson('/api/v1/me/invoice-requests', validInvoicePayload([$this->payment->id], $override))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field, 'errors');

        expect(InvoiceRequest::count())->toBe(0);
        Bus::assertNothingDispatched();
    })->with([
        'bad rfc' => [['rfc' => 'NOPE'], 'rfc'],
        'generic rfc' => [['rfc' => 'XAXX010101000'], 'rfc'],
        'cp too short' => [['postal_code' => '0660'], 'postal_code'],
        'cp letters' => [['postal_code' => '06A00'], 'postal_code'],
        'unknown regime' => [['tax_regime' => '999'], 'tax_regime'],
        'regime 610 is not offered' => [['tax_regime' => '610'], 'tax_regime'],
        'unknown uso' => [['cfdi_use' => 'ZZ9'], 'cfdi_use'],
        'uso CP01 is not offered' => [['cfdi_use' => 'CP01'], 'cfdi_use'],
        'uso CN01 is not offered' => [['cfdi_use' => 'CN01'], 'cfdi_use'],
        'moral regime on a fisica RFC' => [['tax_regime' => '601'], 'tax_regime'],
        'fisica regime on a moral RFC' => [['rfc' => 'ABC010101AAA', 'tax_regime' => '612'], 'tax_regime'],
        'regime 616 with a non S01 uso' => [['tax_regime' => '616', 'cfdi_use' => 'G03'], 'cfdi_use'],
        'regime 616 with G02 (business rule)' => [['tax_regime' => '616', 'cfdi_use' => 'G02'], 'cfdi_use'],
        'uso D01 on a regime that does not allow it' => [['tax_regime' => '626', 'cfdi_use' => 'D01'], 'cfdi_use'],
        'uso G03 on a regime that does not allow it' => [['tax_regime' => '605', 'cfdi_use' => 'G03'], 'cfdi_use'],
        'newline in legal name' => [['legal_name' => "Acme\nSA"], 'legal_name'],
        'control char in legal name' => [['legal_name' => "Acme\x07SA"], 'legal_name'],
        'duplicate payment ids' => [['payment_ids' => [1, 1]], 'payment_ids.0'],
        'more than 50 payment ids' => [['payment_ids' => range(1, 51)], 'payment_ids'],
        'missing name' => [['legal_name' => ''], 'legal_name'],
        'bad email' => [['email' => 'nope'], 'email'],
        'no payments' => [['payment_ids' => []], 'payment_ids'],
        'payment id not int' => [['payment_ids' => ['x']], 'payment_ids.0'],
    ]);

    it('answers 422 on payment_ids for a payment that is not eligible', function (string $reason, callable $arrange): void {
        $payment = $arrange($this->user);

        $response = $this->postJson('/api/v1/me/invoice-requests', validInvoicePayload([$payment->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_ids', 'errors');

        expect(InvoiceRequest::count())->toBe(0);
        Bus::assertNothingDispatched();
        expect($response->json('errors.payment_ids.0'))->toBeString()->not->toBeEmpty();
    })->with([
        'refunded' => ['not_eligible', fn (User $u) => Payment::factory()->create(['user_id' => $u->id, 'status' => PaymentStatus::Refunded, 'refunded_at' => now(), 'paid_at' => now()])],
        'out of window' => ['deadline', fn (User $u) => Payment::factory()->create(['user_id' => $u->id, 'paid_at' => now()->subMonths(2)])],
        'other user payment' => ['not_found', fn (User $u) => Payment::factory()->create(['user_id' => User::factory(), 'paid_at' => now()])],
        'unknown id' => ['not_found', fn (User $u) => Payment::factory()->make(['id' => 999999])],
    ]);

    it('answers 422 with a distinct message when the payment is already claimed', function (): void {
        $this->postJson('/api/v1/me/invoice-requests', validInvoicePayload([$this->payment->id]))->assertCreated();

        $second = $this->postJson('/api/v1/me/invoice-requests', validInvoicePayload([$this->payment->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_ids', 'errors');

        expect($second->json('errors.payment_ids.0'))->toContain('ya tiene una solicitud')
            ->and(InvoiceRequest::count())->toBe(1);
    });

    it('is limited to candidates', function (UserRole $role): void {
        Sanctum::actingAs(User::factory()->create()->assignRole($role->value));

        $this->postJson('/api/v1/me/invoice-requests', validInvoicePayload([$this->payment->id]))->assertForbidden();
        expect(InvoiceRequest::count())->toBe(0);
    })->with([UserRole::Recruiter, UserRole::CompanyUser, UserRole::Admin]);
});

describe('GET /me/invoice-requests', function (): void {
    it('lists only the own requests with their payments', function (): void {
        $mine = InvoiceRequest::factory()->create(['user_id' => $this->user->id]);
        InvoiceRequest::factory()->create();

        $this->getJson('/api/v1/me/invoice-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    });

    it('shows an own request', function (): void {
        $mine = InvoiceRequest::factory()->create(['user_id' => $this->user->id]);

        $this->getJson("/api/v1/me/invoice-requests/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id)
            ->assertJsonPath('data.rfc', 'XXXX010101AAA');
    });

    it('answers 404 for another user request, identical to a missing one, without leaking it', function (): void {
        $other = InvoiceRequest::factory()->create(['rfc' => 'ZZZZ010101ZZZ']);

        $foreign = $this->getJson("/api/v1/me/invoice-requests/{$other->id}")->assertNotFound();
        $missing = $this->getJson('/api/v1/me/invoice-requests/999999')->assertNotFound();

        expect($foreign->getContent())->not->toContain('ZZZZ010101ZZZ')
            ->and($foreign->json('message'))->toBe($missing->json('message'));
    });
});

describe('billing notification job', function (): void {
    function makeRequest(User $user, Payment $payment, array $attributes = []): InvoiceRequest
    {
        $request = InvoiceRequest::factory()->create(['user_id' => $user->id, ...$attributes]);
        $request->payments()->create([
            'payment_id' => $payment->id,
            'claimed_payment_id' => $payment->id,
            'amount' => $payment->amount,
            'paid_at' => $payment->paid_at,
        ]);

        return $request;
    }

    it('sends the billing email and stamps billing_notified_at', function (): void {
        Notification::fake();
        $this->payment->update(['receipt_url' => 'https://pay.stripe.com/receipts/abc']);
        $request = makeRequest($this->user, $this->payment);

        (new NotifyBillingOfInvoiceRequestJob($request->id))->handle();

        expect($request->fresh()->billing_notified_at)->not->toBeNull();
        Notification::assertSentOnDemand(
            InvoiceRequestedNotification::class,
            function ($notification, $channels, $notifiable) use ($request): bool {
                $mail = $notification->toMail($notifiable);
                $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

                return $notifiable->routes['mail'] === 'facturacion@humae.test'
                    && str_contains($text, 'Ana Candidata')
                    && str_contains($text, 'XXXX010101AAA')
                    && str_contains($text, 'https://pay.stripe.com/receipts/abc')
                    && str_contains($text, 'https://app.humae.test/admin/facturacion/'.$request->id);
            },
        );
    });

    it('does not send twice once stamped', function (): void {
        Notification::fake();
        $request = makeRequest($this->user, $this->payment, ['billing_notified_at' => now()]);

        (new NotifyBillingOfInvoiceRequestJob($request->id))->handle();

        Notification::assertNothingSent();
    });

    it('renders user-supplied text as inert in the billing email', function (): void {
        $user = User::factory()->create(['name' => 'Eve [click](https://evil.example/u) *bold*']);
        $request = makeRequest($user, $this->payment, [
            'legal_name' => 'Acme [x](https://evil.example) <b>SA</b> `code` _it_ https://evil.example/bare',
        ]);

        $mail = (new InvoiceRequestedNotification($request))->toMail((object) []);
        $html = (string) $mail->render();
        $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

        expect($html)->not->toContain('href="https://evil.example')
            ->and($html)->not->toContain('<b>SA</b>')
            ->and($text)->not->toContain('[x](https://evil.example)')
            ->and($text)->not->toContain('[click](https://evil.example/u)')
            ->and($html)->toContain('[x]')
            ->and($html)->toContain('Acme');
    });

    it('keeps the request and leaves the stamp empty when the mail fails, without the RFC in logs', function (): void {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        Event::listen(NotificationSending::class, function (): void {
            throw new RuntimeException('SMTP down for '.ENDPOINT_RFC);
        });

        $this->postJson('/api/v1/me/invoice-requests', validInvoicePayload([$this->payment->id]))->assertCreated();

        $request = InvoiceRequest::firstOrFail();
        expect($request->billing_notified_at)->toBeNull()
            ->and($logged)->not->toBeEmpty()
            ->and(implode("\n", $logged))->not->toContain(ENDPOINT_RFC)
            ->and(implode("\n", $logged))->not->toContain('Persona de Prueba');
    });

    it('logs without failing when no billing address is configured', function (): void {
        Notification::fake();
        config(['billing.email' => null]);
        $request = makeRequest($this->user, $this->payment);

        (new NotifyBillingOfInvoiceRequestJob($request->id))->handle();

        Notification::assertNothingSent();
        expect($request->fresh()->billing_notified_at)->toBeNull();
    });

    it('logs the final failure without the RFC', function (): void {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $request = makeRequest($this->user, $this->payment);

        (new NotifyBillingOfInvoiceRequestJob($request->id))->failed(new RuntimeException('boom '.ENDPOINT_RFC));

        expect(implode("\n", $logged))->toContain((string) $request->id)->not->toContain(ENDPOINT_RFC);
    });
});
