<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivot many-to-many entre candidatos y el catálogo `vacancy_types`
        // (tiempo completo, medio tiempo, freelance, contrato, becario, …).
        // Permite que un candidato declare a qué jornadas está abierto y que
        // el directorio los filtre por OR-semantics.
        Schema::create('candidate_work_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_profile_id')
                ->constrained('candidate_profiles')
                ->cascadeOnDelete();
            $table->foreignId('vacancy_type_id')
                ->constrained('vacancy_types')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['candidate_profile_id', 'vacancy_type_id'], 'uq_candidate_vacancy_type');
            $table->index('vacancy_type_id', 'idx_cws_vacancy_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_work_schedules');
    }
};
