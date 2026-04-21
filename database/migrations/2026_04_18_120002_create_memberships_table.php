<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('membership_plan_id')->constrained('membership_plans')->restrictOnDelete();
            $t->string('status', 30)->default('pending');     // see MembershipStatus enum
            $t->timestamp('started_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 500)->nullable();
            $t->boolean('auto_renew')->default(false);
            $t->timestamps();

            $t->index(['user_id', 'status']);
            $t->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
