<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PaymentResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $payments = $user->payments()
            ->with('currency')
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->success(
            message: 'Historial de pagos.',
            data: PaymentResource::collection($payments),
            meta: [
                'pagination' => [
                    'current_page' => $payments->currentPage(),
                    'per_page' => $payments->perPage(),
                    'total' => $payments->total(),
                    'last_page' => $payments->lastPage(),
                ],
            ],
        );
    }
}
