<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\MembershipService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Manda los dos avisos de expiración de membresía: el previo (ventana de
 * `MembershipService::EXPIRY_WARNING_DAYS` días) y el de "ya expiró".
 *
 * Va separado de `ExpireMembershipsJob` porque ese cambia estado y este
 * comunica: mezclarlos haría que un job llamado "expire" le escriba a
 * candidatos que todavía están vigentes.
 */
class NotifyMembershipExpirationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(MembershipService $service): void
    {
        $expiring = $service->notifyExpiring();
        $expired = $service->notifyExpired();

        if ($expiring > 0 || $expired > 0) {
            Log::info('Membership expiration notices sent.', [
                'expiring' => $expiring,
                'expired' => $expired,
            ]);
        }
    }
}
