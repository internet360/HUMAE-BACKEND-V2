<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `batch_uuid` a `activity_log`.
 *
 * La migración original (2026_04_17_194649) se escribió contra el esquema de
 * spatie/laravel-activitylog v5: trae `attribute_changes` y no trae
 * `batch_uuid`. Pero el proyecto declara `^4.0`, y v4 escribe `batch_uuid` en
 * cada inserción —lo hace su propio stub
 * `add_batch_uuid_column_to_activity_log_table`—, así que sin esta columna toda
 * escritura a la bitácora falla con "table activity_log has no column named
 * batch_uuid".
 *
 * La contradicción no se veía porque el `vendor/` de desarrollo tenía v5
 * instalado, resuelto por un `composer.lock` generado en PHP 8.5. Al fijar la
 * plataforma en 8.2 —la que sirven los servidores— el lock bajó a v4.12.3 y el
 * esquema quedó a la vista.
 *
 * `attribute_changes` se deja donde está: v4 no la conoce y es nullable, así que
 * no estorba, y quitarla rompería el día que se suba a v5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->uuid('batch_uuid')->nullable()->after('properties');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn('batch_uuid');
        });
    }
};
