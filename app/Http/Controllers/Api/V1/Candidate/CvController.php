<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CvGenerationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CvController extends Controller
{
    public function __construct(
        private readonly CvGenerationService $service,
    ) {}

    public function download(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->service->generate($user);

        return response($result['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
