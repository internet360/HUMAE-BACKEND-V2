<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the lookups that grow with the data: the admin list matches the RFC
 * exactly and filters by creation date; the webhook pruner scans by age. Each is
 * created only when missing so a partial earlier run can be re-applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('invoice_requests', ['rfc'])) {
            Schema::table('invoice_requests', fn (Blueprint $t) => $t->index('rfc'));
        }

        if (! Schema::hasIndex('invoice_requests', ['created_at'])) {
            Schema::table('invoice_requests', fn (Blueprint $t) => $t->index('created_at'));
        }

        if (! Schema::hasIndex('stripe_webhook_events', ['created_at'])) {
            Schema::table('stripe_webhook_events', fn (Blueprint $t) => $t->index('created_at'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('stripe_webhook_events', ['created_at'])) {
            Schema::table('stripe_webhook_events', fn (Blueprint $t) => $t->dropIndex(['created_at']));
        }

        if (Schema::hasIndex('invoice_requests', ['created_at'])) {
            Schema::table('invoice_requests', fn (Blueprint $t) => $t->dropIndex(['created_at']));
        }

        if (Schema::hasIndex('invoice_requests', ['rfc'])) {
            Schema::table('invoice_requests', fn (Blueprint $t) => $t->dropIndex(['rfc']));
        }
    }
};
