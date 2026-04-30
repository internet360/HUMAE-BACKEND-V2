<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancies', function (Blueprint $table): void {
            // 'employee', 'intern' o 'any' (ver App\Enums\VacancyTargetKind).
            // Default 'any' = no discrimina entre empleado y practicante.
            $table->string('target_candidate_kind', 20)->default('any')->after('functional_area_id');
            $table->index('target_candidate_kind');
        });
    }

    public function down(): void
    {
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->dropIndex(['target_candidate_kind']);
            $table->dropColumn('target_candidate_kind');
        });
    }
};
