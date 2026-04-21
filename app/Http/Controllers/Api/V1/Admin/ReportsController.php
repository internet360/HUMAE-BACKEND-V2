<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReportsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class ReportsController extends Controller
{
    public function __construct(
        private readonly ReportsService $reports,
    ) {}

    public function candidatesRegistered(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        [$from, $to] = $this->dateRange($request);

        return $this->success(
            message: 'Candidatos registrados.',
            data: $this->reports->candidatesRegistered($from, $to),
        );
    }

    public function activeMemberships(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        return $this->success(
            message: 'Membresías activas.',
            data: $this->reports->activeMemberships(),
        );
    }

    public function payments(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        [$from, $to] = $this->dateRange($request);

        return $this->success(
            message: 'Pagos.',
            data: $this->reports->payments($from, $to),
        );
    }

    public function expiringMemberships(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $days = (int) $request->input('days', 30);
        $days = max(1, min(365, $days));

        return $this->success(
            message: 'Membresías por vencer.',
            data: $this->reports->expiringMemberships($days),
        );
    }

    public function vacanciesByState(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        return $this->success(
            message: 'Vacantes por estado.',
            data: $this->reports->vacanciesByState(),
        );
    }

    public function interviews(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        [$from, $to] = $this->dateRange($request);

        return $this->success(
            message: 'Entrevistas.',
            data: $this->reports->interviews($from, $to),
        );
    }

    public function recruiterEffectiveness(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        return $this->success(
            message: 'Efectividad por reclutador.',
            data: $this->reports->recruiterEffectiveness(),
        );
    }

    public function timeToFill(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        return $this->success(
            message: 'Tiempo de contratación.',
            data: $this->reports->timeToFill(),
        );
    }

    public function mostSearchedProfiles(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $limit = (int) $request->input('limit', 20);
        $limit = max(1, min(100, $limit));

        return $this->success(
            message: 'Perfiles más buscados.',
            data: $this->reports->mostSearchedProfiles($limit),
        );
    }

    private function authorizeStaff(Request $request): void
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            abort(HttpStatus::HTTP_UNAUTHORIZED);
        }

        if (! $user->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value])) {
            abort(HttpStatus::HTTP_FORBIDDEN);
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dateRange(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse((string) $request->input('to'))
            : now();

        $from = $request->filled('from')
            ? Carbon::parse((string) $request->input('from'))
            : $to->copy()->subDays(30);

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
