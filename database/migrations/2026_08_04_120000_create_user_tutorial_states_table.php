<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fase 16 §5.1: estado del tutorial de bienvenida de cada rol (una fila
        // por usuario y tutorial, para siempre). `status` y `channel` se
        // guardan como VARCHAR y se castean a enums PHP 8.3 en el modelo
        // (ARCHITECTURE.md §4), no como ENUM de MySQL.
        Schema::create('user_tutorial_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tutorial_key', 64);
            $table->string('status');
            $table->unsignedInteger('version');
            $table->string('channel')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tutorial_key'], 'uq_user_tutorial');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_tutorial_states');
    }
};
