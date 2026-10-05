<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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

        Schema::table('payments', function (Blueprint $t): void {
            $t->dropIndex(['stripe_payment_intent_id']);
            $t->unique('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t): void {
            $t->dropUnique(['stripe_payment_intent_id']);
            $t->index('stripe_payment_intent_id');
        });
    }
};
