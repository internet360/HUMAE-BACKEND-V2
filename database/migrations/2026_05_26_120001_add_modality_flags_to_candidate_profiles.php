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
            // open_to_remote ya existe. Agregamos los otros dos para cubrir las 3
            // modalidades (presencial / remoto / híbrido) y poder filtrar por
            // cualquier combinación desde el directorio.
            $table->boolean('open_to_onsite')->default(true)->after('open_to_remote');
            $table->boolean('open_to_hybrid')->default(false)->after('open_to_onsite');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table): void {
            $table->dropColumn(['open_to_onsite', 'open_to_hybrid']);
        });
    }
};
