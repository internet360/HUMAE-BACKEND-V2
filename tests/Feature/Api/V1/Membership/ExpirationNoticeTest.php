<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Jobs\ExpireMembershipsJob;
use App\Jobs\NotifyMembershipExpirationsJob;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\User;
use App\Notifications\MembershipExpiredNotification;
use App\Notifications\MembershipExpiringNotification;
use App\Services\MembershipService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $mxn = SalaryCurrency::factory()->create(['code' => 'MXN']);
    MembershipPlan::factory()->create([
        'code' => 'candidate_6m',
        'salary_currency_id' => $mxn->id,
        'duration_days' => 180,
        'is_active' => true,
    ]);
});

/**
 * Crea una membresía activa que vence en `$expiresAt`.
 */
function membershipExpiringAt(DateTimeInterface|string $expiresAt): Membership
{
    $plan = MembershipPlan::where('code', 'candidate_6m')->firstOrFail();

    return Membership::factory()->create([
        'user_id' => User::factory(),
        'membership_plan_id' => $plan->id,
        'status' => MembershipStatus::Active,
        'started_at' => now()->subDays(177),
        'expires_at' => $expiresAt,
    ]);
}

function runNotifyJob(): void
{
    (new NotifyMembershipExpirationsJob)->handle(app(MembershipService::class));
}

function runExpireJob(): void
{
    (new ExpireMembershipsJob)->handle(app(MembershipService::class));
}

it('warns the candidate when the membership expires inside the window', function (): void {
    Notification::fake();

    $membership = membershipExpiringAt(now()->addDays(3)->subMinutes(5));

    runNotifyJob();

    Notification::assertSentToTimes(
        $membership->user,
        MembershipExpiringNotification::class,
        1,
    );

    expect($membership->fresh()->expiry_warning_sent_at)->not->toBeNull();
});

it('warns on the very last day, before the membership is flipped to expired', function (): void {
    Notification::fake();

    // Vence hoy más tarde: `expireStale()` todavía no la toca, así que sigue
    // activa y le corresponde el aviso previo, no el de "ya expiró".
    $membership = membershipExpiringAt(now()->addHours(6));

    runNotifyJob();

    Notification::assertSentTo($membership->user, MembershipExpiringNotification::class);
    Notification::assertNotSentTo($membership->user, MembershipExpiredNotification::class);
});

it('never sends the expiring warning twice, no matter how many times the job runs', function (): void {
    Notification::fake();

    $membership = membershipExpiringAt(now()->addDays(2));

    // Cuatro corridas: la ventana dura varios días y el job es diario.
    runNotifyJob();
    runNotifyJob();
    runNotifyJob();
    runNotifyJob();

    Notification::assertSentToTimes(
        $membership->user,
        MembershipExpiringNotification::class,
        1,
    );
});

it('leaves alone memberships that expire beyond the window', function (): void {
    Notification::fake();

    $membership = membershipExpiringAt(now()->addDays(4));

    runNotifyJob();

    Notification::assertNothingSent();
    expect($membership->fresh()->expiry_warning_sent_at)->toBeNull();
});

it('sends the expired notice once the membership is actually expired', function (): void {
    Notification::fake();

    $membership = membershipExpiringAt(now()->subDay());
    runExpireJob();

    runNotifyJob();
    runNotifyJob();

    Notification::assertSentToTimes(
        $membership->user,
        MembershipExpiredNotification::class,
        1,
    );
});

it('does not notify memberships that were already expired before this feature shipped', function (): void {
    Notification::fake();

    // Lo que deja el backfill de la migración: expirada y ya sellada.
    $membership = membershipExpiringAt(now()->subMonths(3));
    $membership->forceFill([
        'status' => MembershipStatus::Expired,
        'expired_notice_sent_at' => now()->subMonths(3),
    ])->save();

    runNotifyJob();

    Notification::assertNothingSent();
});

it('ignores cancelled memberships', function (): void {
    Notification::fake();

    $membership = membershipExpiringAt(now()->addDay());
    $membership->forceFill([
        'status' => MembershipStatus::Cancelled,
        'cancelled_at' => now(),
    ])->save();

    runNotifyJob();

    Notification::assertNothingSent();
});

it('sends each template exactly once across the full lifecycle', function (): void {
    Notification::fake();

    $membership = membershipExpiringAt(now()->addDays(3)->subMinutes(5));
    $user = $membership->user;

    // Día -3: entra a la ventana y se avisa.
    runNotifyJob();

    // Días -2 y -1: el job sigue corriendo y no debe repetir nada.
    $this->travel(1)->days();
    runExpireJob();
    runNotifyJob();

    $this->travel(1)->days();
    runExpireJob();
    runNotifyJob();

    Notification::assertSentToTimes($user, MembershipExpiringNotification::class, 1);
    Notification::assertNotSentTo($user, MembershipExpiredNotification::class);

    // Ya venció: cambia la plantilla.
    $this->travel(2)->days();
    runExpireJob();
    runNotifyJob();

    // Y al día siguiente tampoco se repite.
    $this->travel(1)->days();
    runNotifyJob();

    Notification::assertSentToTimes($user, MembershipExpiringNotification::class, 1);
    Notification::assertSentToTimes($user, MembershipExpiredNotification::class, 1);

    $fresh = $membership->fresh();
    expect($fresh->status)->toBe(MembershipStatus::Expired);
    expect($fresh->expiry_warning_sent_at)->not->toBeNull();
    expect($fresh->expired_notice_sent_at)->not->toBeNull();
});

it('stores an in-app notification alongside each email', function (): void {
    $membership = membershipExpiringAt(now()->addDays(2));
    $user = $membership->user;

    runNotifyJob();

    expect($user->notifications()->count())->toBe(1);
    expect($user->notifications()->first()->data['type'])->toBe('membership.expiring');
});
