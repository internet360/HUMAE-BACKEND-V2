<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $t): void {
            $t->id();
            // One row per processed Stripe event. The unique index is the mutual
            // exclusion between concurrent deliveries of the same event.
            $t->string('event_id', 120)->unique();
            $t->string('type', 120);
            $t->boolean('livemode')->default(false);
            $t->timestamp('processed_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
