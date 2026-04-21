<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 2)->unique();        // ISO 3166-1 alpha-2
            $table->string('name', 120);
            $table->string('phone_code', 10)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('code', 10);
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'code']);
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('state_id')->constrained('states')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 160);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['state_id', 'slug']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::dropIfExists('states');
        Schema::dropIfExists('countries');
    }
};
