<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los perfiles que la empresa señaló dentro de una solicitud.
 *
 * `vacancy_assignment_id` es el puente hacia el pipeline: cuando HUMAE acepta
 * un perfil nace su asignación y queda apuntada aquí. Antes de eso es null, y
 * ésa es justamente la frontera — la empresa señaló a alguien, pero nadie fue
 * presentado todavía.
 *
 * `rejection_reason` acompaña a `vetado` y la empresa sí lo lee: un veto sin
 * motivo es una puerta cerrada sin explicación, y el cliente rehace el trabajo
 * a ciegas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_request_candidates', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('interview_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id')->constrained()->cascadeOnDelete();

            $table->string('state', 20)->default('pendiente')->index();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('vacancy_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // Señalar dos veces a la misma persona en una solicitud no significa
            // nada, y duplicaría su asignación al aceptarla.
            $table->unique(['interview_request_id', 'candidate_profile_id'], 'irc_request_candidate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_request_candidates');
    }
};
