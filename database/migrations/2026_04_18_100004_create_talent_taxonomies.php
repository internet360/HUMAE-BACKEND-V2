<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 80)->unique();
            $t->string('name', 160);
            $t->string('category', 80)->nullable(); // p.ej. 'tecnica', 'blanda'
            $t->string('description', 500)->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->index('category');
        });

        Schema::create('languages', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 5)->unique(); // ISO 639-1 (es, en, fr, pt, de, ...)
            $t->string('name', 80);
            $t->string('native_name', 80)->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
        Schema::dropIfExists('skills');
    }
};
