<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StripeWebhookEvent;
use Illuminate\Console\Command;

/**
 * Deletes webhook dedup rows past the retention window.
 *
 * The table only exists to recognise a redelivered event, and Stripe stops
 * retrying after 3 days, so rows older than that carry no protection. Without
 * pruning it grows forever (every event of the account lands here, including
 * the ones we ignore). Deletes in small batches so a big backlog never holds a
 * long lock on a table the webhook writes to.
 */
class PruneStripeWebhookEvents extends Command
{
    /** Stripe retries a failed delivery for up to 3 days. */
    private const MIN_DAYS = 7;

    protected $signature = 'billing:prune-webhook-events
        {--days=90 : Keep rows newer than this many days}
        {--batch=1000 : Rows deleted per query}';

    protected $description = 'Delete Stripe webhook dedup rows older than the retention window';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $batch = max(1, (int) $this->option('batch'));

        if ($days < self::MIN_DAYS) {
            $this->error('Retention must be at least '.self::MIN_DAYS.' days: a shorter window could let a redelivered event be processed twice.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = StripeWebhookEvent::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += StripeWebhookEvent::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === $batch);

        $this->info("Pruned {$deleted} webhook event row(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
