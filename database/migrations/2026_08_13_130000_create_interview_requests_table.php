<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitud de entrevistas del empleador.
 *
 * Es la intención del cliente —«quiero conocer a estas personas, en uno de
 * estos dos horarios»— y deliberadamente NO es un `VacancyAssignment`. Las
 * asignaciones son la curación de HUMAE (ARCHITECTURE.md §6, «Asignar
 * candidatos a vacante — Empresa cliente: ❌»); si la empresa escribiera ahí,
 * dejaría de existir el filtro por el que paga. Aquí pide, y HUMAE convierte.
 *
 * Los dos horarios viven en columnas y no en una tabla hija porque son
 * exactamente dos, no «uno o más»: es una regla del negocio, y una tabla hija
 * la volvería opinable. `interviews` ya modela ese mismo par con
 * `scheduled_at` + `alternate_scheduled_at`, así que además es consistente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users');

            $table->string('state', 30)->default('pendiente')->index();

            $table->dateTime('proposed_slot_1_at');
            $table->dateTime('proposed_slot_2_at');
            $table->string('timezone', 64)->default('America/Mexico_City');

            // Mensaje opcional de la empresa al reclutador. No es una nota
            // interna: la empresa la escribe y la lee.
            $table->text('note')->nullable();

            $table->foreignId('assigned_recruiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_requests');
    }
};
