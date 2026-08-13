<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Referencia pública opaca del candidato.
 *
 * La empresa cliente pasa a navegar el talento en vista previa anónima, así que
 * necesita poder nombrar a un candidato —para seleccionarlo— sin recibir su
 * `id`. `routes/api.php` ya dejó escrito por qué el id no puede salir del lado
 * interno: «con `{candidate}` se podría enumerar la base de talento probando
 * ids». Esta columna es la forma de nombrar sin enumerar.
 *
 * UUID v4 y no ULID a propósito: el ULID lleva marca de tiempo en el prefijo, y
 * eso filtraría el orden de alta de la base —quién es reciente y quién lleva
 * meses— a un actor externo que sólo debería ver perfiles profesionales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table): void {
            $table->uuid('public_reference')->nullable()->after('user_id');
        });

        // Backfill: cada perfil existente necesita su propio UUID, así que no
        // hay un `update()` masivo posible. Se recorre en chunks para no cargar
        // la base entera en memoria.
        DB::table('candidate_profiles')
            ->select('id')
            ->orderBy('id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('candidate_profiles')
                        ->where('id', $row->id)
                        ->update(['public_reference' => (string) Str::uuid()]);
                }
            });

        // El índice único va después del backfill: antes, todas las filas
        // existentes comparten el valor NULL y en MySQL 8 eso pasa, pero deja
        // una ventana en la que dos altas concurrentes podrían colisionar.
        Schema::table('candidate_profiles', function (Blueprint $table): void {
            $table->unique('public_reference');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table): void {
            $table->dropUnique(['public_reference']);
            $table->dropColumn('public_reference');
        });
    }
};
