<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('psychometric_attempts', function (Blueprint $t): void {
            // Anular un intento es una intervención de HUMAE sobre la medición de
            // una persona: tiene que quedar quién y por qué, no sólo un status
            // distinto. Sin esto, un candidato que reclama "me anularon la prueba"
            // no tiene respuesta posible.
            $t->timestamp('cancelled_at')->nullable()->after('submitted_at');
            $t->string('cancelled_reason', 500)->nullable()->after('cancelled_at');
            $t->foreignId('cancelled_by')
                ->nullable()
                ->after('cancelled_reason')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('psychometric_attempts', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('cancelled_by');
            $t->dropColumn(['cancelled_at', 'cancelled_reason']);
        });
    }
};
