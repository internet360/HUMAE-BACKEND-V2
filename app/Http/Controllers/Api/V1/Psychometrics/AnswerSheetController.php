<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Psychometrics;

use App\Http\Controllers\Controller;
use App\Models\PsychometricAttempt;
use App\Services\PsychometricReportingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Hoja de respuestas de un intento, ítem por ítem — para HUMAE.
 *
 * ── Por qué este endpoint existe con reservas ────────────────────────────────
 *
 * El resto del módulo expone el resultado AGREGADO (dimensiones, resumen) y
 * deliberadamente no las respuestas: publicarlas vuelve el cuestionario
 * reconstruible, y un instrumento psicométrico filtrado pierde validez. Se
 * agregó porque el equipo necesita auditar cómo se llegó a un puntaje.
 *
 * De ahí los dos límites:
 *  - Sólo HUMAE (`viewPsychometrics` sobre el perfil del candidato). La empresa
 *    cliente NO llega acá: sigue viendo únicamente el agregado.
 *  - El candidato lee sus propias respuestas por `/me/psychometrics/attempts/{id}`,
 *    donde el payload no incluye puntajes por opción. Ver la respuesta que uno
 *    dio es razonable; ver cuánto valía es la clave de calificación.
 */
class AnswerSheetController extends Controller
{
    public function __construct(
        private readonly PsychometricReportingService $reporting,
    ) {}

    public function index(PsychometricAttempt $attempt): JsonResponse
    {
        $profile = $attempt->candidateProfile;

        if ($profile === null) {
            return $this->error(
                message: 'El intento no tiene un candidato asociado.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        $this->authorize('viewPsychometrics', $profile);

        return $this->success(
            message: 'Hoja de respuestas.',
            data: [
                'attempt_id' => $attempt->id,
                'status' => $attempt->status?->value,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'test' => $attempt->test !== null ? [
                    'id' => $attempt->test->id,
                    'name' => $attempt->test->name,
                    'code' => $attempt->test->code,
                ] : null,
                'items' => $this->reporting->answerSheet($attempt),
            ],
        );
    }
}
