<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table): void {
            // 'employee' o 'intern' (ver App\Enums\CandidateKind).
            // Nullable para no romper perfiles existentes; el form de perfil
            // exigirá uno de los valores antes de marcar el perfil como activo.
            $table->string('candidate_kind', 20)->nullable()->after('functional_area_id');
            $table->index('candidate_kind');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table): void {
            $table->dropIndex(['candidate_kind']);
            $table->dropColumn('candidate_kind');
        });
    }
};
