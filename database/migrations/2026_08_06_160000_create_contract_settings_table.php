<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Términos comerciales vigentes del contrato, editables desde el panel de admin.
 *
 * Tabla de un solo registro (id = 1). Reemplaza a `config/contracts.php` como
 * fuente de verdad: antes cambiar el porcentaje de honorarios exigía editar el
 * `.env` del servidor, correr `config:cache` y reiniciar — algo que solo podía
 * hacer quien tuviera SSH.
 *
 * Columnas tipadas y no la tabla `settings` genérica (key-value) a propósito:
 * estos valores terminan impresos en un documento que obliga a pagar dinero, y
 * un porcentaje guardado como string es una fuente de errores silenciosos.
 *
 * Lo que se edita acá aplica solo a contratos NUEVOS. Los ya firmados conservan
 * su copia en `company_contracts.terms`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_settings', function (Blueprint $t): void {
            $t->id();

            // Identidad del prestador y de quien firma por él.
            $t->string('provider_name', 200);
            $t->string('signatory_name', 200)->nullable();
            $t->string('signatory_title', 200)->nullable();

            /*
             * Firma escaneada del apoderado, en el disco privado. Nullable: sin
             * ella el contrato se genera igual pero sale firmado por una sola
             * parte, y el panel lo advierte.
             */
            $t->string('signature_path', 300)->nullable();

            // Honorarios (cláusula Tercera).
            $t->string('fee_kind', 40);                          // percentage_annual_gross | monthly_salary_multiple | fixed_amount
            $t->decimal('fee_value', 12, 2);
            $t->string('fee_amount_words', 200)->nullable();     // obligatorio solo con fixed_amount

            // Plazo de pago (cláusula Cuarta).
            $t->unsignedSmallInteger('payment_days');
            $t->string('payment_day_kind', 20);                  // habiles | naturales

            // Garantía de sustitución en días naturales (cláusula Quinta).
            $t->unsignedSmallInteger('warranty_days');

            // Lugar de firma y fuero (cláusula Séptima y cierre).
            $t->string('city', 200)->nullable();
            $t->string('jurisdiction', 300);

            /*
             * Versión de los términos. Se estampa en el snapshot de cada contrato
             * para poder responder "¿qué versión aceptó esta empresa?" sin
             * comparar campo por campo. Se incrementa en cada guardado.
             */
            $t->unsignedInteger('version')->default(1);

            // Auditoría: quién cambió las condiciones comerciales y cuándo.
            $t->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_settings');
    }
};
