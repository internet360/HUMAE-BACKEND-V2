<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El catálogo de puestos del cliente ("PUESTOS DE BUSQUEDA.docx") está
        // estructurado por áreas macro: cada puesto pertenece a un área. La FK
        // permite selects en cascada (Área → Puesto) en el frontend y filtrar
        // el directorio por área sin depender del texto del nombre.
        //
        // Nullable a propósito: los puestos ya guardados no tienen área y un
        // puesto administrado desde el panel admin puede quedar sin clasificar.
        Schema::table('positions', function (Blueprint $t): void {
            $t->foreignId('functional_area_id')
                ->nullable()
                ->after('name')
                ->constrained('functional_areas')
                ->nullOnDelete();

            $t->index(['functional_area_id', 'sort_order'], 'positions_area_sort_index');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $t): void {
            $t->dropIndex('positions_area_sort_index');
            $t->dropConstrainedForeignId('functional_area_id');
        });
    }
};
