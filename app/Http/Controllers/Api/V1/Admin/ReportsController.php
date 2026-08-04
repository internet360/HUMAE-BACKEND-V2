<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReportsService;
use App\Support\Reports\ReportScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * §6 grants "Ver reportes" with a different scope per role — "Reclutador ✅ (sus
 * procesos), Empresa cliente ✅ (sus vacantes), Admin ✅ (todos)". The endpoints
 * used to hand recruiters global aggregates and refuse client companies
 * outright (F-11).
 *
 * Each action now declares which family it belongs to and hands the resolved
 * {@see ReportScope} to the service. Nothing here decides who sees what; the
 * scope does, in one place.
 */
class ReportsController extends Controller
{
    public function __construct(
        private readonly ReportsService $reports,
    ) {}

    public function candidatesRegistered(Request $request): JsonResponse
    {
        $this->platformScope($request);
        [$from, $to] = $this->dateRange($request);

        return $this->success(
            message: 'Candidatos registrados.',
            data: $this->reports->candidatesRegistered($from, $to),
        );
    }

    public function activeMemberships(Request $request): JsonResponse
    {
        $this->platformScope($request);

        return $this->success(
            message: 'Membresías activas.',
            data: $this->reports->activeMemberships(),
        );
    }

    public function payments(Request $request): JsonResponse
    {
        $this->platformScope($request);
        [$from, $to] = $this->dateRange($request);

        return $this->success(
            message: 'Pagos.',
            data: $this->reports->payments($from, $to),
        );
    }

    public function expiringMemberships(Request $request): JsonResponse
    {
        $this->platformScope($request);
        $days = (int) $request->input('days', 30);
        $days = max(1, min(365, $days));

        return $this->success(
            message: 'Membresías por vencer.',
            data: $this->reports->expiringMemberships($days),
        );
    }

    public function vacanciesByState(Request $request): JsonResponse
    {
        $scope = $this->processScope($request);

        return $this->success(
            message: 'Vacantes por estado.',
            data: $this->reports->vacanciesByState($scope),
        );
    }

    public function interviews(Request $request): JsonResponse
    {
        $scope = $this->processScope($request);
        [$from, $to] = $this->dateRange($request);

        return $this->success(
            message: 'Entrevistas.',
            data: $this->reports->interviews($from, $to, $scope),
        );
    }

    public function recruiterEffectiveness(Request $request): JsonResponse
    {
        $scope = $this->scopeFor($request);

        // Vacancy-shaped, but it measures HUMAE's own team. A client reads its
        // own hiring funnel, not its supplier's performance review.
        if (! $scope->seesRecruiterPerformance) {
            abort(HttpStatus::HTTP_FORBIDDEN);
        }

        return $this->success(
            message: 'Efectividad por reclutador.',
            data: $this->reports->recruiterEffectiveness($scope),
        );
    }

    public function timeToFill(Request $request): JsonResponse
    {
        $scope = $this->processScope($request);

        return $this->success(
            message: 'Tiempo de contratación.',
            data: $this->reports->timeToFill($scope),
        );
    }

    public function mostSearchedProfiles(Request $request): JsonResponse
    {
        // The payload is candidate names out of the talent base, so §6's
        // "Ver directorio de candidatos — Empresa cliente ❌" applies verbatim.
        $this->platformScope($request);
        $limit = (int) $request->input('limit', 20);
        $limit = max(1, min(100, $limit));

        return $this->success(
            message: 'Perfiles más buscados.',
            data: $this->reports->mostSearchedProfiles($limit),
        );
    }

    private function scopeFor(Request $request): ReportScope
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            abort(HttpStatus::HTTP_UNAUTHORIZED);
        }

        return ReportScope::forUser($user);
    }

    /**
     * A report dimensioned by vacancy: every granted role reads it, narrowed to
     * its own slice (§6 "sus procesos" / "sus vacantes").
     */
    private function processScope(Request $request): ReportScope
    {
        $scope = $this->scopeFor($request);

        if (! $scope->seesProcessReports) {
            abort(HttpStatus::HTTP_FORBIDDEN);
        }

        return $scope;
    }

    /**
     * A HUMAE-wide business metric with no vacancy dimension — registrations,
     * memberships, payments, directory demand. "Sus vacantes" cannot narrow a
     * payment, and §6 closes the candidate axis to the client company, so these
     * stay with HUMAE.
     */
    private function platformScope(Request $request): ReportScope
    {
        $scope = $this->scopeFor($request);

        if (! $scope->seesPlatformMetrics) {
            abort(HttpStatus::HTTP_FORBIDDEN);
        }

        return $scope;
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
