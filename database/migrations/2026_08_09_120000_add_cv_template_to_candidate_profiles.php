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
            // Plantilla elegida por el candidato para renderizar su CV. El valor
            // se escribe literal —y no desde App\Enums\CvTemplate— para que la
            // migración no dependa de código de aplicación que puede cambiar.
            $table->string('cv_template', 20)->default('classic')->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table): void {
            $table->dropColumn('cv_template');
        });
    }
};
