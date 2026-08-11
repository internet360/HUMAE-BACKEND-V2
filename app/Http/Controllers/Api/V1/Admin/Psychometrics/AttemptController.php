<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Psychometrics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Psychometrics\CancelAttemptRequest;
use App\Http\Resources\V1\Psychometric\StaffAttemptResultResource;
use App\Models\PsychometricAttempt;
use App\Models\User;
use App\Services\PsychometricTestService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

/**
 * Intervenciones de HUMAE sobre un intento concreto.
 *
 * Anular es hoy la única, y existe porque el límite de un intento por prueba no
 * tenía reparación posible: un candidato al que se le cortó la conexión, o que
 * contestó de mala fe, quedaba trabado para siempre y soporte no tenía qué
 * hacer.
 */
class AttemptController extends Controller
{
    public function __construct(
        private readonly PsychometricTestService $service,
    ) {}

    public function cancel(
        CancelAttemptRequest $request,
        PsychometricAttempt $attempt,
    ): JsonResponse {
        $this->authorize('psychometric.manage');

        /** @var User $actor */
        $actor = $request->user();

        try {
            $cancelled = $this->service->cancelAttempt(
                $attempt,
                $actor,
                $request->validated('reason'),
            );
        } catch (Throwable $e) {
            return $this->error(
                message: $e->getMessage(),
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        return $this->success(
            message: 'Intento anulado. El candidato puede volver a responder esta prueba.',
            data: StaffAttemptResultResource::make($cancelled),
        );
    }
}
