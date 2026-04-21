<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['industries', 'company_sizes', 'ownership_types'] as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id();
                $t->string('code', 80)->unique();
                $t->string('name', 160);
                $t->string('description', 500)->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['industries', 'company_sizes', 'ownership_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
