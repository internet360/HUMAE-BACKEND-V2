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
        Schema::table('memberships', function (Blueprint $t): void {
            // Candados de "una sola vez" para los dos avisos de expiración.
            // El job corre a diario sobre una ventana de varios días, así que
            // sin persistir el envío reenviaría el mismo correo cada día.
            // Se guardan como timestamp y no como boolean porque saber *cuándo*
            // se avisó es lo que permite auditar un reclamo de "no me llegó".
            $t->timestamp('expiry_warning_sent_at')->nullable()->after('cancel_reason');
            $t->timestamp('expired_notice_sent_at')->nullable()->after('expiry_warning_sent_at');
        });

        // Backfill defensivo: las membresías que YA estaban expiradas antes de
        // este cambio nunca recibieron el aviso, y no deben recibirlo ahora.
        // Sin esto, la primera corrida del job le escribe "tu membresía expiró"
        // a todo el histórico de golpe.
        //
        // Se usa `updated_at` en lugar de `now()` porque es la mejor
        // aproximación disponible al momento en que `expireStale()` la marcó.
        DB::table('memberships')
            ->where('status', 'expired')
            ->whereNull('expired_notice_sent_at')
            ->update(['expired_notice_sent_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $t): void {
            $t->dropColumn(['expiry_warning_sent_at', 'expired_notice_sent_at']);
        });
    }
};
