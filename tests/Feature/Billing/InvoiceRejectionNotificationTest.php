<?php

declare(strict_types=1);

use App\Enums\InvoiceRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Events\PaymentReversed;
use App\Listeners\FlagInvoiceForCancellation;
use App\Models\InvoiceRequest;
use App\Models\InvoiceRequestPayment;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\InvoiceRequestRejectedNotification;
use App\Services\InvoiceRequestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

const REJECTION_RFC = 'RJCT010101AAA';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'America/Mexico_City'));
    $this->owner = User::factory()->create()->assignRole(UserRole::Candidate->value);
    $this->payment = Payment::factory()->create([
        'user_id' => $this->owner->id,
        'status' => PaymentStatus::Succeeded,
        'paid_at' => now()->subDay(),
    ]);
    $this->request = app(InvoiceRequestService::class)->claim($this->owner, [$this->payment->id], [
        'rfc' => REJECTION_RFC, 'legal_name' => 'Ana Fiscal', 'tax_regime' => '612',
        'postal_code' => '06600', 'cfdi_use' => 'G03', 'email' => 'fiscal@example.com',
    ]);
});

function adminRejects(InvoiceRequest $request, string $reason): void
{
    Sanctum::actingAs(User::factory()->create()->assignRole(UserRole::Admin->value));
    test()->patchJson("/api/v1/admin/invoice-requests/{$request->id}/status", ['status' => 'rejected', 'reason' => $reason])->assertOk();
}

it('queues one rejection mail to the CFDI email with the reason when an admin rejects', function (): void {
    Notification::fake();

    adminRejects($this->request, 'El RFC no coincide con la constancia');

    Notification::assertSentOnDemandTimes(InvoiceRequestRejectedNotification::class, 1);
    Notification::assertSentOnDemand(InvoiceRequestRejectedNotification::class, function (InvoiceRequestRejectedNotification $n, array $channels, AnonymousNotifiable $notifiable): bool {
        $mail = $n->toMail($notifiable);
        $text = implode("\n", [$mail->subject, ...$mail->introLines, $mail->actionUrl, ...$mail->outroLines]);

        return $notifiable->routes['mail'] === 'fiscal@example.com'
            && $channels === ['mail']
            && str_contains($text, 'El RFC no coincide con la constancia')
            && str_contains($text, '/dashboard/membresia/facturacion')
            && str_contains($text, 'volver a solicitar')
            && ! str_contains($text, REJECTION_RFC);
    });
});

it('is queued after commit', function (): void {
    $notification = new InvoiceRequestRejectedNotification($this->request, 1, 1);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)->and($notification->afterCommit)->toBeTrue();
});

it('escapes Markdown in the reason', function (): void {
    $this->request->forceFill(['rejection_reason' => '[Haz clic](https://evil.test) <b>x</b>'])->save();

    $mail = (new InvoiceRequestRejectedNotification($this->request, 1, 1))->toMail(new AnonymousNotifiable);
    $text = implode("\n", $mail->introLines);

    expect($text)->toContain('\\[Haz clic\\]\\(https://evil.test\\)')->and($text)->not->toContain('<b>');
});

it('does not send a second mail when the rejection is replayed', function (): void {
    Notification::fake();
    adminRejects($this->request, 'Datos incorrectos');

    test()->patchJson("/api/v1/admin/invoice-requests/{$this->request->id}/status", ['status' => 'rejected', 'reason' => 'Datos incorrectos'])->assertUnprocessable();

    Notification::assertSentOnDemandTimes(InvoiceRequestRejectedNotification::class, 1);
});

it('does not send a rejection mail for other transitions', function (): void {
    Notification::fake();
    Sanctum::actingAs(User::factory()->create()->assignRole(UserRole::Admin->value));

    test()->patchJson("/api/v1/admin/invoice-requests/{$this->request->id}/status", ['status' => 'in_progress'])->assertOk();

    Notification::assertNothingSent();
});

it('queues a rejection mail when a reversed payment rejects the request automatically', function (): void {
    Notification::fake();
    $this->payment->forceFill(['status' => PaymentStatus::Refunded, 'refunded_at' => now()])->save();

    app(FlagInvoiceForCancellation::class)->handle(new PaymentReversed($this->payment, 'refunded', 'evt_rej'));

    expect($this->request->fresh()->status)->toBe(InvoiceRequestStatus::Rejected);
    Notification::assertSentOnDemandTimes(InvoiceRequestRejectedNotification::class, 1);
    Notification::assertSentOnDemand(InvoiceRequestRejectedNotification::class, function (InvoiceRequestRejectedNotification $n, array $channels, AnonymousNotifiable $notifiable): bool {
        $text = implode("\n", $n->toMail($notifiable)->introLines);

        return $notifiable->routes['mail'] === 'fiscal@example.com'
            && str_contains($text, 'Pago reembolsado')
            && str_contains($text, 'no se puede volver a solicitar');
    });
});

it('does not send a rejection mail when an issued request moves to cancellation pending', function (): void {
    Notification::fake();
    $this->request->forceFill(['status' => InvoiceRequestStatus::Issued, 'cfdi_uuid' => '6f1c2b0e-4a5d-4c3b-9e8f-1a2b3c4d5e6f', 'issued_at' => now()])->save();
    $this->payment->forceFill(['status' => PaymentStatus::Refunded, 'refunded_at' => now()])->save();

    app(FlagInvoiceForCancellation::class)->handle(new PaymentReversed($this->payment, 'refunded', 'evt_cp'));

    expect($this->request->fresh()->status)->toBe(InvoiceRequestStatus::CancellationPending);
    Notification::assertNotSentTo(new AnonymousNotifiable, InvoiceRequestRejectedNotification::class);
    Notification::assertSentOnDemandTimes(InvoiceRequestRejectedNotification::class, 0);
});

it('says the other payments can be requested again when only one was reversed', function (): void {
    $other = Payment::factory()->create(['user_id' => $this->owner->id, 'status' => PaymentStatus::Succeeded, 'paid_at' => now()->subHours(3)]);
    InvoiceRequestPayment::create([
        'invoice_request_id' => $this->request->id, 'payment_id' => $other->id, 'claimed_payment_id' => $other->id,
        'amount' => $other->amount, 'paid_at' => $other->paid_at,
    ]);
    Notification::fake();
    $this->payment->forceFill(['status' => PaymentStatus::Refunded, 'refunded_at' => now()])->save();

    app(FlagInvoiceForCancellation::class)->handle(new PaymentReversed($this->payment, 'refunded', 'evt_two'));

    Notification::assertSentOnDemand(InvoiceRequestRejectedNotification::class, function (InvoiceRequestRejectedNotification $n, array $channels, AnonymousNotifiable $notifiable): bool {
        $text = implode("\n", $n->toMail($notifiable)->introLines);

        return str_contains($text, 'Los demás pagos') && str_contains($text, 'volver a solicitar');
    });
});
