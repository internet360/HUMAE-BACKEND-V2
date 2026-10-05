<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PLAIN = 'payments_stripe_payment_intent_id_index';

    private const UNIQUE = 'payments_stripe_payment_intent_id_unique';

    public function up(): void
    {
        $duplicates = DB::table('payments')
            ->select('stripe_payment_intent_id')
            ->whereNotNull('stripe_payment_intent_id')
            ->groupBy('stripe_payment_intent_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('stripe_payment_intent_id')
            ->all();

        // Never guess which payment "wins": a human must reconcile them first.
        if ($duplicates !== []) {
            throw new RuntimeException(
                'Cannot make payments.stripe_payment_intent_id unique, duplicate payment intents exist: '
                .implode(', ', $duplicates).'. Reconcile them manually and run the migration again.'
            );
        }

        // Add the unique index BEFORE dropping the plain one: DDL implicitly commits in MySQL, so if the
        // unique ALTER fails the column must keep its old index. Each step is tolerant so a re-run is safe.
        if (! Schema::hasIndex('payments', self::UNIQUE)) {
            Schema::table('payments', function (Blueprint $t): void {
                $t->unique('stripe_payment_intent_id');
            });
        }

        if (Schema::hasIndex('payments', self::PLAIN)) {
            Schema::table('payments', function (Blueprint $t): void {
                $t->dropIndex(self::PLAIN);
            });
        }
    }

    public function down(): void
    {
        // Mirror of up(): restore the plain index first so the column is never left unindexed.
        if (! Schema::hasIndex('payments', self::PLAIN)) {
            Schema::table('payments', function (Blueprint $t): void {
                $t->index('stripe_payment_intent_id', self::PLAIN);
            });
        }

        if (Schema::hasIndex('payments', self::UNIQUE)) {
            Schema::table('payments', function (Blueprint $t): void {
                $t->dropUnique(self::UNIQUE);
            });
        }
    }
};
