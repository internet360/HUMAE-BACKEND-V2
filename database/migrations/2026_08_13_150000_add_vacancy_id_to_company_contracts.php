<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contratos con honorarios propios para una vacante concreta.
 *
 * Nace de una decisión de negocio: lo que HUMAE factura tiene que estar
 * respaldado siempre por algo que la empresa firmó. Antes, el campo
 * `vacancies.fee_percentage` podía ganarle al contrato, y entonces la factura
 * decía un número que el documento firmado no mencionaba en ninguna parte —
 * cuando el cliente abría su contrato, tenía razón él.
 *
 * La solución no fue prohibir el honorario especial, sino exigir que también se
 * firme. Con `vacancy_id`:
 *
 *   - NULL → contrato maestro de la empresa. Rige la relación completa y es el
 *     que mira el gate de entrevistas (la cláusula Primera vive ahí).
 *   - con valor → adenda para esa vacante. Sólo cambia los honorarios de esa
 *     colocación.
 *
 * Se modela como un contrato más y no como una tabla aparte porque es
 * exactamente eso: mismo PDF, misma firma, misma constancia NOM-151, mismo
 * folio. Una tabla «addenda» habría duplicado los seis campos de evidencia que
 * le dan valor legal, y el día que uno de los dos caminos se olvide de sellar,
 * nadie se entera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_contracts', function (Blueprint $table): void {
            $table->foreignId('vacancy_id')->nullable()->after('company_id')
                ->constrained()->cascadeOnDelete();

            $table->index(['company_id', 'vacancy_id']);
        });
    }

    public function down(): void
    {
        Schema::table('company_contracts', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'vacancy_id']);
            $table->dropConstrainedForeignName('vacancy_id');
        });
    }
};
