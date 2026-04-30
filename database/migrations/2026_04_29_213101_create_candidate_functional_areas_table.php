<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivote candidate_profile ↔ functional_area (PDF cosasfaltanteshumae,
        // punto 1: el candidato puede seleccionar múltiples áreas y marcar
        // una como "principal"). El campo legacy `candidate_profiles.
        // functional_area_id` se mantiene y se sincroniza con la primaria
        // via ProfileService.
        Schema::create('candidate_functional_areas', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('candidate_profile_id')
                ->constrained('candidate_profiles')
                ->cascadeOnDelete();
            $t->foreignId('functional_area_id')
                ->constrained('functional_areas')
                ->cascadeOnDelete();
            $t->boolean('is_primary')->default(false);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();

            $t->unique(['candidate_profile_id', 'functional_area_id'], 'candidate_functional_areas_unique');
            $t->index(['candidate_profile_id', 'is_primary']);
        });

        // Campo libre opcional ("otra" del PDF) para áreas que no estén en
        // el catálogo administrable. Visible en el perfil pero no usable
        // como filtro estructurado.
        Schema::table('candidate_profiles', function (Blueprint $t): void {
            $t->string('other_area_text', 200)->nullable()->after('candidate_kind');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $t): void {
            $t->dropColumn('other_area_text');
        });
        Schema::dropIfExists('candidate_functional_areas');
    }
};
