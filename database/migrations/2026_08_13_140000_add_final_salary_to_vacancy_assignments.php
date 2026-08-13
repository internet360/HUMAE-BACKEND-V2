<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sueldo final confirmado de una colocación.
 *
 * Vive en la asignación y no en la vacante a propósito: la vacante publica un
 * rango (`salary_min`/`salary_max`) y cada persona negocia el suyo dentro —o
 * fuera— de ese rango. Guardarlo en la vacante haría que contratar a la segunda
 * persona sobrescribiera el sueldo de la primera, y con él la base del cargo ya
 * devengado.
 *
 * Se guarda el período junto al monto porque «38,000» no significa nada solo. La
 * anualización para calcular honorarios se hace desde este par, nunca asumiendo
 * mensual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancy_assignments', function (Blueprint $table): void {
            $table->decimal('final_salary_amount', 12, 2)->nullable()->after('score');
            $table->string('final_salary_period', 20)->nullable()->after('final_salary_amount');
            $table->foreignId('final_salary_currency_id')->nullable()->after('final_salary_period')
                ->constrained('salary_currencies')->nullOnDelete();
            $table->foreignId('final_salary_confirmed_by_user_id')->nullable()->after('final_salary_currency_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('final_salary_confirmed_at')->nullable()->after('final_salary_confirmed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('vacancy_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignName('final_salary_currency_id');
            $table->dropConstrainedForeignName('final_salary_confirmed_by_user_id');
            $table->dropColumn([
                'final_salary_amount',
                'final_salary_period',
                'final_salary_currency_id',
                'final_salary_confirmed_by_user_id',
                'final_salary_confirmed_at',
            ]);
        });
    }
};
