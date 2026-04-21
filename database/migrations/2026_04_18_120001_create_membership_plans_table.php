<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 60)->unique();                 // 'candidate_6m'
            $t->string('name', 160);
            $t->string('description', 500)->nullable();
            $t->foreignId('salary_currency_id')->constrained('salary_currencies')->restrictOnDelete();
            $t->decimal('price', 10, 2);                      // 499.00
            $t->unsignedSmallInteger('duration_days');        // 180
            $t->string('stripe_price_id', 120)->nullable();   // price_xxx
            $t->string('stripe_product_id', 120)->nullable(); // prod_xxx
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};
