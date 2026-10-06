<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureActiveMembership;
use App\Http\Middleware\EnsureVerifiedEmail;
use App\Jobs\ExpireMembershipsJob;
use App\Jobs\NotifyMembershipExpirationsJob;
use App\Support\ApiExceptionHandler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'verified_email' => EnsureVerifiedEmail::class,
            'active_membership' => EnsureActiveMembership::class,
        ]);

        // Laravel runs SubstituteBindings ahead of unlisted middleware, so a
        // `permission:` gate would see a 404 for a missing model id before it could
        // answer 403, letting any authenticated user enumerate ids. The gate goes
        // right before binding, after authentication (already listed earlier).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: PermissionMiddleware::class);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new ExpireMembershipsJob)->daily()->name('memberships:expire');

        // Una hora después de `memberships:expire` a propósito: el aviso de
        // "ya expiró" se dispara por status, así que necesita que la corrida
        // que marca `expired` haya terminado. Ambos son jobs en cola, y el
        // scheduler no garantiza el orden de dos tareas del mismo minuto.
        //
        // El offset es comodidad, no correctitud: los sellos
        // `expiry_warning_sent_at` / `expired_notice_sent_at` hacen el proceso
        // idempotente, así que en el peor caso el aviso sale al día siguiente
        // en lugar de perderse o duplicarse.
        $schedule->job(new NotifyMembershipExpirationsJob)
            ->dailyAt('01:00')
            ->name('memberships:notify-expirations');

        // Los contratos se firman aunque CINCEL esté caído (quedan sin
        // constancia); esto los sella cuando el proveedor vuelve.
        // `withoutOverlapping` porque cada constancia reintenta hasta 5 veces
        // con espera, y dos corridas simultáneas pelearían por los mismos.
        // La tabla de dedup de webhooks solo protege contra reentregas (Stripe
        // reintenta hasta 3 días): se poda para que no crezca sin tope.
        $schedule->command('billing:prune-webhook-events')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->name('billing:prune-webhook-events');

        $schedule->command('contracts:retry-timestamps')
            ->hourly()
            ->withoutOverlapping()
            ->name('contracts:retry-timestamps');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        ApiExceptionHandler::register($exceptions);
    })->create();
