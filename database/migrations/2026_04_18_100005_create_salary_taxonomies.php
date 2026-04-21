<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_currencies', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 3)->unique(); // ISO 4217 (MXN, USD, EUR)
            $t->string('name', 80);
            $t->string('symbol', 4);
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_currencies');
    }
};
