<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MembershipPlanResource;
use App\Http\Resources\V1\MembershipResource;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

class MembershipController extends Controller
{
    public function __construct(
        private readonly MembershipService $service,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $current = $user->memberships()
            ->with('plan.currency')
            ->orderByDesc('started_at')
            ->first();

        $history = $user->memberships()
            ->with('plan.currency')
            ->orderByDesc('started_at')
            ->get();

        $defaultPlan = MembershipPlan::where('code', 'candidate_6m')
            ->where('is_active', true)
            ->with('currency')
            ->first();

        return $this->success(
            message: 'Estado de membresía.',
            data: [
                'current' => $current !== null ? MembershipResource::make($current) : null,
                'history' => MembershipResource::collection($history),
                'available_plan' => $defaultPlan !== null
                    ? MembershipPlanResource::make($defaultPlan)
                    : null,
            ],
        );
    }

    public function checkout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasActiveMembership()) {
            return $this->error(
                message: 'Ya tienes una membresía activa.',
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        $plan = MembershipPlan::where('code', 'candidate_6m')
            ->where('is_active', true)
            ->with('currency')
            ->first();

        if ($plan === null) {
            return $this->error(
                message: 'No hay un plan de membresía disponible en este momento.',
                status: HttpStatus::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        try {
            $result = $this->service->createCheckoutSession($user, $plan);
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                message: 'No pudimos iniciar el pago. Intenta de nuevo en unos minutos.',
                status: HttpStatus::HTTP_BAD_GATEWAY,
            );
        }

        return $this->success(
            message: 'Sesión de pago creada.',
            data: $result,
            status: HttpStatus::HTTP_CREATED,
        );
    }
}
