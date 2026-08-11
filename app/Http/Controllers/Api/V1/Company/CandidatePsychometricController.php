<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Psychometric\CompanyAttemptResultResource;
use App\Models\VacancyAssignment;
use App\Services\PsychometricReportingService;
use Illuminate\Http\JsonResponse;

/**
 * Resultados psicométricos que la empresa cliente puede leer.
 *
 * Se direcciona por ASIGNACIÓN, no por candidato. No es un detalle de REST: la
 * empresa no tiene ninguna vía legítima para nombrar un `candidate_profile_id`
 * suelto —no navega el directorio (§6)— así que el único identificador que puede
 * ofrecer es el de un candidato que HUMAE le presentó en una de sus vacantes.
 * Aceptar `{candidate}` acá habría abierto la puerta a enumerar la base de
 * talento probando ids.
 */
class CandidatePsychometricController extends Controller
{
    public function __construct(
        private readonly PsychometricReportingService $reporting,
    ) {}

    public function index(VacancyAssignment $assignment): JsonResponse
    {
        // La Policy verifica dos cosas: que la asignación esté en una etapa
        // visible para la empresa, y que el usuario pertenezca a esa empresa.
        $this->authorize('viewPsychometrics', $assignment);

        $profile = $assignment->candidateProfile;

        if ($profile === null) {
            return $this->success(
                message: 'Sin resultados psicométricos.',
                data: CompanyAttemptResultResource::collection([]),
            );
        }

        return $this->success(
            message: 'Resultados psicométricos del candidato.',
            data: CompanyAttemptResultResource::collection(
                $this->reporting->scoredAttempts($profile),
            ),
        );
    }
}
