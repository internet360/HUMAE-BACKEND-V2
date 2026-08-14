<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cargo por colocación: los honorarios que HUMAE devenga al contratarse un
 * candidato.
 *
 * Tabla nueva y no `payments`. Ese modelo es cien por ciento membresías
 * (`user_id`, `membership_id`, `membership_plan_id`, campos de Stripe) y su
 * columna `fee_amount` es la comisión de la PASARELA, no la de HUMAE —
 * `MembershipService` la fija en cero. Reutilizarlo habría mezclado dos cobros
 * con nombres colisionados.
 *
 * Todo el cálculo queda CONGELADO en la fila: la forma de honorarios, su valor,
 * el sueldo con su período, la base anual y el contrato que lo sostiene. Es un
 * registro contable, no una vista: si mañana alguien edita el porcentaje de la
 * vacante o la empresa firma un contrato nuevo, lo ya devengado no puede
 * cambiar de monto por debajo. Una factura emitida no se recalcula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placement_charges', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained()->cascadeOnDelete();

            // Una colocación, un cargo. Sin esto un doble `hire` cobraría dos veces.
            $table->foreignId('vacancy_assignment_id')->unique()->constrained()->cascadeOnDelete();

            // Qué contrato sostiene el cobro. Es la pregunta que hace un
            // contador cuando la empresa objeta la factura.
            $table->foreignId('company_contract_id')->nullable()->constrained()->nullOnDelete();

            $table->string('state', 20)->default('por_facturar')->index();

            // `vacancy` cuando la vacante traía honorarios propios; `contract`
            // cuando se tomaron de los términos firmados. Sin este campo nadie
            // puede reconstruir por qué salió ese número.
            $table->string('fee_source', 20);
            $table->string('fee_kind', 40);
            $table->decimal('fee_value', 12, 2);

            $table->decimal('final_salary_amount', 12, 2);
            $table->string('final_salary_period', 20);
            $table->decimal('annual_base', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->foreignId('salary_currency_id')->nullable()->constrained('salary_currencies')->nullOnDelete();

            $table->foreignId('salary_confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accrued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accrued_at');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_charges');
    }
};
