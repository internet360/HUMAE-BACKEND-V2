<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureActiveMembership;
use App\Http\Middleware\EnsureVerifiedEmail;
use App\Jobs\ExpireMembershipsJob;
use App\Support\ApiExceptionHandler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new ExpireMembershipsJob)->daily()->name('memberships:expire');

        // Los contratos se firman aunque CINCEL esté caído (quedan sin
        // constancia); esto los sella cuando el proveedor vuelve.
        // `withoutOverlapping` porque cada constancia reintenta hasta 5 veces
        // con espera, y dos corridas simultáneas pelearían por los mismos.
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
