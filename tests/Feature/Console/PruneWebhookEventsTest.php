<?php

declare(strict_types=1);

use App\Models\StripeWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function webhookEventRow(string $id, int $daysOld): void
{
    DB::table('stripe_webhook_events')->insert([
        'event_id' => $id,
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'processed_at' => now()->subDays($daysOld),
        'created_at' => now()->subDays($daysOld),
        'updated_at' => now()->subDays($daysOld),
    ]);
}

it('prunes dedup rows older than 90 days and keeps the rest', function (): void {
    webhookEventRow('evt_old', 91);
    webhookEventRow('evt_older', 400);
    webhookEventRow('evt_edge', 89);
    webhookEventRow('evt_new', 0);

    $this->artisan('billing:prune-webhook-events')->assertSuccessful();

    expect(StripeWebhookEvent::pluck('event_id')->all())->toEqualCanonicalizing(['evt_edge', 'evt_new']);
});

it('honours a custom retention and removes more rows than one batch', function (): void {
    foreach (range(1, 5) as $i) {
        webhookEventRow("evt_batch_{$i}", 10);
    }
    webhookEventRow('evt_fresh', 1);

    $this->artisan('billing:prune-webhook-events', ['--days' => 7, '--batch' => 2])->assertSuccessful();

    expect(StripeWebhookEvent::pluck('event_id')->all())->toBe(['evt_fresh']);
});

it('clamps the batch size into 1..10000', function (string $batch, int $expectedLimit): void {
    webhookEventRow('evt_clamp', 10);
    $limits = [];
    DB::listen(function ($query) use (&$limits): void {
        if (str_contains($query->sql, 'stripe_webhook_events') && preg_match('/limit (\d+)/i', $query->sql, $m) === 1) {
            $limits[] = (int) $m[1];
        }
    });

    $this->artisan('billing:prune-webhook-events', ['--days' => 7, '--batch' => $batch])->assertSuccessful();

    expect(StripeWebhookEvent::count())->toBe(0)
        ->and($limits)->not->toBeEmpty()
        ->and(array_values(array_unique($limits)))->toBe([$expectedLimit]);
})->with([
    'zero' => ['0', 1],
    'negative' => ['-5', 1],
    'above the cap' => ['99999999', 10000],
]);

it('rejects a retention shorter than a week', function (): void {
    webhookEventRow('evt_guard', 10);

    $this->artisan('billing:prune-webhook-events', ['--days' => 6])->assertFailed();

    expect(StripeWebhookEvent::count())->toBe(1);
});

it('is scheduled daily', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('billing:prune-webhook-events')
        ->assertSuccessful();
});

it('indexes the columns the admin list and the pruner filter on', function (): void {
    expect(Schema::hasIndex('invoice_requests', ['rfc']))->toBeTrue()
        ->and(Schema::hasIndex('invoice_requests', ['created_at']))->toBeTrue()
        ->and(Schema::hasIndex('stripe_webhook_events', ['created_at']))->toBeTrue();
});
