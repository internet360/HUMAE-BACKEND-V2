<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $t->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->nullOnDelete();

            $t->string('status', 30)->default('pending');    // see PaymentStatus enum
            $t->foreignId('salary_currency_id')->constrained('salary_currencies')->restrictOnDelete();
            $t->decimal('amount', 10, 2);
            $t->decimal('fee_amount', 10, 2)->default(0);
            $t->decimal('net_amount', 10, 2)->default(0);

            // Stripe
            $t->string('provider', 30)->default('stripe');
            $t->string('stripe_session_id', 200)->nullable();
            $t->string('stripe_payment_intent_id', 200)->nullable();
            $t->string('stripe_charge_id', 200)->nullable();
            $t->string('stripe_customer_id', 120)->nullable();
            $t->string('receipt_url', 600)->nullable();

            $t->timestamp('paid_at')->nullable();
            $t->timestamp('refunded_at')->nullable();
            $t->decimal('refund_amount', 10, 2)->nullable();
            $t->string('refund_reason', 500)->nullable();

            $t->json('metadata')->nullable();

            $t->timestamps();

            $t->index(['user_id', 'status']);
            $t->index('paid_at');
            $t->index('stripe_session_id');
            $t->index('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
