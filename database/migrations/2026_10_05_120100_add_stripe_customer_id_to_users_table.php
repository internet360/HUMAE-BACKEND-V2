<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            // 1:1 anchor between a user and their Stripe Customer. Nullable and
            // unique: existing users get theirs lazily, NULLs never collide.
            $t->string('stripe_customer_id', 120)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->dropUnique(['stripe_customer_id']);
            $t->dropColumn('stripe_customer_id');
        });
    }
};
