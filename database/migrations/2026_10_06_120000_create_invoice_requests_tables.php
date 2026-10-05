<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_requests', function (Blueprint $t): void {
            $t->id();
            // Fiscal records are legal records: deleting a user must not wipe them.
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('status', 30)->default('requested')->index();
            $t->string('rfc', 13);
            $t->string('legal_name', 300);
            $t->string('tax_regime', 3);
            $t->string('postal_code', 5);
            $t->string('cfdi_use', 4);
            $t->string('email');
            $t->string('rejection_reason', 500)->nullable();
            $t->timestamp('billing_notified_at')->nullable();
            $t->timestamps();
        });

        Schema::create('invoice_request_payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('invoice_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payment_id')->constrained()->restrictOnDelete();
            // Snapshot: the invoice must match what was charged at claim time.
            $t->decimal('amount', 12, 2);
            $t->timestamp('paid_at');
            // Equals payment_id while the request is active, NULL once rejected
            // or cancelled. NULLs do not collide in a unique index, so a payment
            // can be re-requested but never claimed twice at once.
            $t->foreignId('claimed_payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_request_payments');
        Schema::dropIfExists('invoice_requests');
    }
};
