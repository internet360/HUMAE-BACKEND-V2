<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Psychometrics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Psychometrics\ReviewAttemptRequest;
use App\Http\Resources\V1\Psychometric\StaffAttemptResultResource;
use App\Models\PsychometricAttempt;
use App\Models\User;
use App\Services\PsychometricReportingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

/**
 * Interpretación de HUMAE sobre un resultado psicométrico.
 *
 * `dimension_scores` es el dato medido; esto es lo que el equipo LEE en ese dato,
 * y queda firmado. Las columnas `recommendations`, `reviewed_by` y `reviewed_at`
 * existían desde la primera migración sin que nada las escribiera: la UI las
 * mostraba siempre vacías.
 *
 * La empresa cliente nunca lo ve — `CompanyAttemptResultResource` excluye estos
 * campos a propósito.
 */
class AttemptReviewController extends Controller
{
    public function __construct(
        private readonly PsychometricReportingService $reporting,
    ) {}

    public function update(
        ReviewAttemptRequest $request,
        PsychometricAttempt $attempt,
    ): JsonResponse {
        $profile = $attempt->candidateProfile;

        if ($profile === null) {
            return $this->error(
                message: 'El intento no tiene un candidato asociado.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        $this->authorize('reviewPsychometrics', $profile);

        /** @var User $reviewer */
        $reviewer = $request->user();

        try {
            $this->reporting->annotate(
                $attempt,
                $reviewer,
                $request->validated('recommendations'),
            );
        } catch (Throwable $e) {
            return $this->error(
                message: $e->getMessage(),
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        return $this->success(
            message: 'Interpretación guardada.',
            data: StaffAttemptResultResource::make(
                $attempt->fresh(['test', 'result']) ?? $attempt,
            ),
        );
    }
}
