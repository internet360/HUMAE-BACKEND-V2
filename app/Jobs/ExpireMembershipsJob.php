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

class ExpireMembershipsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(MembershipService $service): void
    {
        $count = $service->expireStale();

        if ($count > 0) {
            Log::info('Memberships expired by scheduled job.', ['count' => $count]);
        }
    }
}
