<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega un segundo horario propuesto en la entrevista. La empresa propone
 * dos slots; el candidato escoge uno al confirmar y el `alternate_scheduled_at`
 * queda en null. El reclutador agrega el meeting_url después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interviews', function (Blueprint $t): void {
            $t->timestamp('alternate_scheduled_at')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $t): void {
            $t->dropColumn('alternate_scheduled_at');
        });
    }
};
