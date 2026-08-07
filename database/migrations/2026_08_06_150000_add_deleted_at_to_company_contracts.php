<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permite anular un contrato sin destruirlo.
     *
     * Un contrato firmado es la prueba de que la empresa aceptó los términos:
     * borrar la fila destruye esa evidencia. Con `deleted_at` un contrato anulado
     * desaparece de `latestContract()` —así la empresa puede volver a firmar—
     * pero la constancia, el PDF y la huella siguen resguardados.
     */
    public function up(): void
    {
        Schema::table('company_contracts', function (Blueprint $t): void {
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('company_contracts', function (Blueprint $t): void {
            $t->dropSoftDeletes();
        });
    }
};
