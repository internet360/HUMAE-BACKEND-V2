<?php

declare(strict_types=1);

use App\Enums\InvoiceRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\InvoiceRequest;
use App\Models\Payment;
use App\Models\User;
use App\Services\InvoiceRequestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

const ADMIN_RFC = 'ADMN010101AAA';
const ADMIN_BASE = '/api/v1/admin/invoice-requests';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'America/Mexico_City'));
    $this->admin = User::factory()->create()->assignRole(UserRole::Admin->value);
    $this->owner = User::factory()->create(['name' => 'Ana Candidata', 'email' => 'ana@example.com'])->assignRole(UserRole::Candidate->value);
    $this->payment = Payment::factory()->create(['user_id' => $this->owner->id, 'paid_at' => now()->subDay()]);
    $this->request = app(InvoiceRequestService::class)->claim($this->owner, [$this->payment->id], [
        'rfc' => ADMIN_RFC, 'legal_name' => 'Ana Fiscal', 'tax_regime' => '612',
        'postal_code' => '06600', 'cfdi_use' => 'G03', 'email' => 'fiscal@example.com',
    ]);
    Sanctum::actingAs($this->admin);
});

function adminStatus(InvoiceRequest $request, string $status, array $extra = []): TestResponse
{
    return test()->patchJson(ADMIN_BASE.'/'.$request->id.'/status', ['status' => $status, ...$extra]);
}

describe('authorization', function (): void {
    it('denies every non-admin role on every route', function (UserRole $role): void {
        Sanctum::actingAs(User::factory()->create()->assignRole($role->value));
        $id = $this->request->id;

        $this->getJson(ADMIN_BASE)->assertForbidden();
        $this->getJson(ADMIN_BASE.'/'.$id)->assertForbidden();
        adminStatus($this->request, 'in_progress')->assertForbidden();
        $this->patchJson(ADMIN_BASE.'/'.$id.'/notes', ['admin_notes' => 'x'])->assertForbidden();

        expect($this->request->fresh()->status)->toBe(InvoiceRequestStatus::Requested)
            ->and($this->request->fresh()->admin_notes)->toBeNull();
    })->with([UserRole::Candidate, UserRole::Recruiter, UserRole::CompanyUser]);

    it('denies an admin role without the permission', function (): void {
        $this->admin->roles->first()->revokePermissionTo('invoices.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson(ADMIN_BASE)->assertForbidden();
    });
});

describe('GET /admin/invoice-requests', function (): void {
    it('lists paginated requests with requester and notification state but no RFC', function (): void {
        $this->getJson(ADMIN_BASE)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->request->id)
            ->assertJsonPath('data.0.status', 'requested')
            ->assertJsonPath('data.0.user.name', 'Ana Candidata')
            ->assertJsonPath('data.0.billing_notified_at', null)
            ->assertJsonPath('data.0.billing_notification', 'pending')
            ->assertJsonPath('data.0.payments_count', 1)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonMissingPath('data.0.rfc');

        expect(json_encode($this->getJson(ADMIN_BASE)->json()))->not->toContain(ADMIN_RFC);
    });

    it('shows a stamped notification as sent', function (): void {
        $this->request->update(['billing_notified_at' => now()]);

        $this->getJson(ADMIN_BASE)->assertJsonPath('data.0.billing_notification', 'sent');
    });

    it('filters by status, date range and search', function (): void {
        $other = User::factory()->create(['name' => 'Beto Otro', 'email' => 'beto@example.com']);
        $old = InvoiceRequest::factory()->create([
            'user_id' => $other->id, 'status' => InvoiceRequestStatus::InProgress,
            'rfc' => 'OTRO010101AAA', 'created_at' => '2026-08-01 10:00:00',
        ]);

        $ids = fn (string $qs) => collect($this->getJson(ADMIN_BASE.$qs)->assertOk()->json('data'))->pluck('id')->all();

        expect($ids(''))->toEqualCanonicalizing([$this->request->id, $old->id])
            ->and($ids('?status=in_progress'))->toBe([$old->id])
            ->and($ids('?from=2026-10-01&to=2026-10-31'))->toBe([$this->request->id])
            ->and($ids('?from=2026-07-01&to=2026-08-31'))->toBe([$old->id])
            ->and($ids('?q=ana'))->toBe([$this->request->id])
            ->and($ids('?q=beto@example'))->toBe([$old->id])
            ->and($ids('?q=OTRO010101AAA'))->toBe([$old->id])
            ->and($ids('?q=nadie'))->toBe([]);
    });

    it('filters by billing notification state', function (): void {
        $sent = InvoiceRequest::factory()->create(['billing_notified_at' => now()]);

        $ids = fn (string $qs) => collect($this->getJson(ADMIN_BASE.$qs)->assertOk()->json('data'))->pluck('id')->all();

        expect($ids('?billing_notification=pending'))->toBe([$this->request->id])
            ->and($ids('?billing_notification=sent'))->toBe([$sent->id])
            ->and($ids(''))->toEqualCanonicalizing([$this->request->id, $sent->id]);
    });

    it('interprets from/to as whole days in the billing timezone, not UTC', function (): void {
        $at = fn (string $local) => Carbon::parse($local, 'America/Mexico_City')->utc();
        $make = fn (string $local) => InvoiceRequest::factory()->create(['created_at' => $at($local)])->id;

        // 20:00 Mexico City on Oct 31 is already Nov 1 in UTC.
        $lateOct31 = $make('2026-10-31 20:00:00');
        $startNov1 = $make('2026-11-01 00:00:00');
        $startOct1 = $make('2026-10-01 00:00:00');
        $endSep30 = $make('2026-09-30 23:59:59');

        $ids = fn (string $qs) => collect($this->getJson(ADMIN_BASE.$qs)->assertOk()->json('data'))->pluck('id')->all();

        expect($ids('?to=2026-10-31&from=2026-10-01'))->toContain($lateOct31)->toContain($startOct1)
            ->not->toContain($startNov1)->not->toContain($endSep30)
            ->and($ids('?from=2026-11-01'))->toContain($startNov1)->not->toContain($lateOct31);
    });

    it('validates filters', function (): void {
        $this->getJson(ADMIN_BASE.'?status=bogus')->assertUnprocessable();
        $this->getJson(ADMIN_BASE.'?from=not-a-date')->assertUnprocessable();
        $this->getJson(ADMIN_BASE.'?from=2026-10-10&to=2026-10-01')->assertUnprocessable();
        $this->getJson(ADMIN_BASE.'?billing_notification=maybe')->assertUnprocessable();
    });
});

describe('GET /admin/invoice-requests/{id}', function (): void {
    it('shows the full fiscal data including the RFC', function (): void {
        $this->getJson(ADMIN_BASE.'/'.$this->request->id)
            ->assertOk()
            ->assertJsonPath('data.rfc', ADMIN_RFC)
            ->assertJsonPath('data.legal_name', 'Ana Fiscal')
            ->assertJsonPath('data.email', 'fiscal@example.com')
            ->assertJsonPath('data.user.email', 'ana@example.com')
            ->assertJsonPath('data.payments.0.payment_id', $this->payment->id)
            ->assertJsonPath('data.admin_notes', null);
    });

    it('answers 404 for an unknown request', function (): void {
        $this->getJson(ADMIN_BASE.'/999999')->assertNotFound();
    });
});

describe('PATCH /admin/invoice-requests/{id}/status', function (): void {
    it('moves requested to in_progress and records an audit entry without the RFC', function (): void {
        adminStatus($this->request, 'in_progress')
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.status_label', 'En proceso');

        $entry = Activity::where('log_name', 'invoice-requests')->latest('id')->first();

        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($this->admin->id)
            ->and($entry->subject_id)->toBe($this->request->id)
            ->and($entry->properties['from'])->toBe('requested')
            ->and($entry->properties['to'])->toBe('in_progress');

        $dump = json_encode(Activity::all()->toArray());
        expect($dump)->not->toContain(ADMIN_RFC)->not->toContain('Ana Fiscal');
    });

    it('requires a reason to reject, stores it and frees the claims', function (): void {
        adminStatus($this->request, 'rejected')->assertUnprocessable()->assertJsonValidationErrors('reason');
        expect($this->request->fresh()->status)->toBe(InvoiceRequestStatus::Requested);

        adminStatus($this->request, 'rejected', ['reason' => 'RFC no coincide con la constancia'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'RFC no coincide con la constancia');

        expect($this->request->payments()->whereNotNull('claimed_payment_id')->count())->toBe(0);
    });

    it('requires a reason for the fiscal cancellation statuses and keeps the status when it is missing', function (string $from, string $to): void {
        $this->request->update(['status' => InvoiceRequestStatus::from($from)]);

        adminStatus($this->request, $to)->assertUnprocessable()->assertJsonValidationErrors('reason');
        adminStatus($this->request, $to, ['reason' => '   '])->assertUnprocessable()->assertJsonValidationErrors('reason');

        expect($this->request->fresh()->status->value)->toBe($from)
            ->and(Activity::where('log_name', 'invoice-requests')->count())->toBe(0);
    })->with([
        'issued to cancellation_pending' => ['issued', 'cancellation_pending'],
        'cancellation_pending to cancelled' => ['cancellation_pending', 'cancelled'],
    ]);

    it('records the reason in the audit entry of every transition without PII', function (string $from, string $to, string $reason): void {
        $this->request->update(['status' => InvoiceRequestStatus::from($from)]);

        adminStatus($this->request, $to, ['reason' => $reason])->assertOk();

        $entry = Activity::where('log_name', 'invoice-requests')->latest('id')->firstOrFail();
        expect($entry->properties['reason'])->toBe($reason)
            ->and($entry->properties['from'])->toBe($from)
            ->and($entry->properties['to'])->toBe($to)
            ->and(json_encode(Activity::all()->toArray()))->not->toContain(ADMIN_RFC)->not->toContain('Ana Fiscal')
            ->and($this->request->fresh()->rejection_reason)->toBe($to === 'rejected' ? $reason : null);
    })->with([
        'rejected' => ['requested', 'rejected', 'RFC no coincide'],
        'in_progress (optional reason)' => ['requested', 'in_progress', 'Se revisa con contabilidad'],
        'cancellation_pending' => ['issued', 'cancellation_pending', 'El cliente pidió cancelar'],
        'cancelled' => ['cancellation_pending', 'cancelled', 'SAT aceptó la cancelación'],
    ]);

    it('audits a transition without reason as such', function (): void {
        adminStatus($this->request, 'in_progress')->assertOk();

        $entry = Activity::where('log_name', 'invoice-requests')->latest('id')->firstOrFail();
        expect($entry->properties->has('reason'))->toBeFalse();
    });

    it('makes the payment eligible again after a rejection', function (): void {
        $service = app(InvoiceRequestService::class);
        expect($service->eligiblePayments($this->owner)->pluck('id')->all())->toBe([]);

        adminStatus($this->request, 'rejected', ['reason' => 'Datos incorrectos'])->assertOk();

        expect($service->eligiblePayments($this->owner)->pluck('id')->all())->toBe([$this->payment->id]);
    });

    it('frees the claims when a pending cancellation is completed', function (): void {
        $this->request->update(['status' => InvoiceRequestStatus::CancellationPending]);

        adminStatus($this->request, 'cancelled', ['reason' => 'Cancelación aceptada por el SAT'])->assertOk()->assertJsonPath('data.status', 'cancelled');

        expect(app(InvoiceRequestService::class)->eligiblePayments($this->owner)->pluck('id')->all())->toBe([$this->payment->id]);
    });

    it('answers 422 for an invalid transition and changes nothing', function (string $from, string $to): void {
        $this->request->update(['status' => InvoiceRequestStatus::from($from)]);

        adminStatus($this->request, $to, ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('status');

        expect($this->request->fresh()->status->value)->toBe($from);
    })->with([
        ['rejected', 'in_progress'],
        ['cancelled', 'in_progress'],
        ['in_progress', 'requested'],
        ['requested', 'cancelled'],
        ['issued', 'rejected'],
    ]);

    it('does not let a stale rejected request be released again', function (): void {
        adminStatus($this->request, 'rejected', ['reason' => 'a'])->assertOk();

        adminStatus($this->request, 'rejected', ['reason' => 'b'])->assertUnprocessable();
        expect($this->request->fresh()->rejection_reason)->toBe('a');
    });

    it('cannot set issued with a plain status change', function (string $from): void {
        $this->request->update(['status' => InvoiceRequestStatus::from($from)]);

        adminStatus($this->request, 'issued')->assertUnprocessable()->assertJsonValidationErrors('status');

        expect($this->request->fresh()->status->value)->toBe($from)
            ->and(Activity::where('log_name', 'invoice-requests')->count())->toBe(0);
    })->with(['requested', 'in_progress', 'cancellation_pending']);

    it('rejects unknown statuses', function (): void {
        adminStatus($this->request, 'bogus')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson(ADMIN_BASE.'/'.$this->request->id.'/status', [])->assertUnprocessable();
    });

    it('keeps a refunded payment ineligible after the request is rejected', function (): void {
        $this->payment->update(['status' => PaymentStatus::Refunded, 'refunded_at' => now()]);

        adminStatus($this->request, 'rejected', ['reason' => 'x'])->assertOk();

        expect(app(InvoiceRequestService::class)->eligiblePayments($this->owner)->pluck('id')->all())->toBe([]);
    });
});

describe('PATCH /admin/invoice-requests/{id}/notes', function (): void {
    it('stores and clears admin notes and audits without their content', function (): void {
        $this->patchJson(ADMIN_BASE.'/'.$this->request->id.'/notes', ['admin_notes' => 'Llamar a Ana'])
            ->assertOk()
            ->assertJsonPath('data.admin_notes', 'Llamar a Ana');

        expect($this->request->fresh()->admin_notes)->toBe('Llamar a Ana')
            ->and(json_encode(Activity::where('log_name', 'invoice-requests')->get()->toArray()))->not->toContain('Llamar a Ana');

        $this->patchJson(ADMIN_BASE.'/'.$this->request->id.'/notes', ['admin_notes' => null])->assertOk();
        expect($this->request->fresh()->admin_notes)->toBeNull();
    });

    it('limits the note length', function (): void {
        $this->patchJson(ADMIN_BASE.'/'.$this->request->id.'/notes', ['admin_notes' => str_repeat('a', 2001)])
            ->assertUnprocessable();
    });

    it('never exposes admin notes to the candidate', function (): void {
        $this->request->update(['admin_notes' => 'interna-secreta']);
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/me/invoice-requests/'.$this->request->id)->assertOk();
        expect(json_encode($this->getJson('/api/v1/me/invoice-requests')->json()))->not->toContain('interna-secreta');
    });
});
