<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('psychometric_tests', function (Blueprint $t): void {
            // Intentos permitidos por candidato. Antes no había techo: un
            // candidato podía rehacer la prueba indefinidamente hasta obtener el
            // perfil que quisiera, y cada intento generaba su propio resultado
            // sin que nada dijera cuál era el válido. En un instrumento
            // psicométrico eso invalida la medición.
            //
            // `1` es el default porque es lo correcto para un instrumento de
            // personalidad. `null` significa ilimitado, explícito, para el caso
            // raro de una prueba de práctica.
            $t->unsignedSmallInteger('max_attempts')->nullable()->default(1)->after('passing_score');
        });

        // Las pruebas que ya existían nacieron sin techo. Se les fija 1 de forma
        // explícita en lugar de dejarlas heredando el default, para que la
        // intención quede escrita en los datos y no dependa del DDL.
        DB::table('psychometric_tests')
            ->whereNull('max_attempts')
            ->update(['max_attempts' => 1]);
    }

    public function down(): void
    {
        Schema::table('psychometric_tests', function (Blueprint $t): void {
            $t->dropColumn('max_attempts');
        });
    }
};
