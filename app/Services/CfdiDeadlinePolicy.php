<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Oldest `paid_at` (UTC) still invoiceable. The calendar month is computed in
 * the billing timezone, never in the app timezone (UTC): a payment at 23:30
 * Mexico City on the last day belongs to that month although it is already the
 * next one in UTC.
 */
class CfdiDeadlinePolicy
{
    public function windowStart(?Carbon $now = null): CarbonImmutable
    {
        $now = CarbonImmutable::instance($now ?? Carbon::now());

        if (config('billing.cfdi.deadline') === 'days') {
            return $now->subDays(max(1, (int) config('billing.cfdi.deadline_days')))->utc();
        }

        return $now->setTimezone((string) config('billing.timezone'))->startOfMonth()->utc();
    }

    public function allows(Carbon $paidAt, ?Carbon $now = null): bool
    {
        return $paidAt->greaterThanOrEqualTo($this->windowStart($now));
    }
}
