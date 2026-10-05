<?php

declare(strict_types=1);

use App\Services\CfdiDeadlinePolicy;
use Illuminate\Support\Carbon;

it('opens the same-month window at the start of the month in Mexico City, in UTC', function (): void {
    config(['billing.cfdi.deadline' => 'same_month']);

    $start = (new CfdiDeadlinePolicy)->windowStart(Carbon::parse('2026-10-31 23:45:00', 'America/Mexico_City'));

    // Mexico City is UTC-6 all year since 2022.
    expect($start->toDateTimeString())->toBe('2026-10-01 06:00:00')
        ->and($start->timezoneName)->toBe('UTC');
});

it('uses the Mexico City month even when UTC is already the next month', function (): void {
    config(['billing.cfdi.deadline' => 'same_month']);

    // 2026-11-01 05:30 UTC is still October 31 in Mexico City.
    $start = (new CfdiDeadlinePolicy)->windowStart(Carbon::parse('2026-11-01 05:30:00', 'UTC'));

    expect($start->toDateTimeString())->toBe('2026-10-01 06:00:00');
});

it('supports a rolling window of N days', function (): void {
    config(['billing.cfdi.deadline' => 'days', 'billing.cfdi.deadline_days' => 10]);

    $start = (new CfdiDeadlinePolicy)->windowStart(Carbon::parse('2026-10-14 18:00:00', 'UTC'));

    expect($start->toDateTimeString())->toBe('2026-10-04 18:00:00');
});

it('falls back to same month for an unknown mode', function (): void {
    config(['billing.cfdi.deadline' => 'bogus']);

    $start = (new CfdiDeadlinePolicy)->windowStart(Carbon::parse('2026-10-14 18:00:00', 'UTC'));

    expect($start->toDateTimeString())->toBe('2026-10-01 06:00:00');
});
