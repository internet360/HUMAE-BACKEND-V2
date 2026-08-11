<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Psychometrics\Concerns;

use App\Models\PsychometricTest;
use App\Services\PsychometricAuthoringService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

/**
 * Guardia de estructura congelada, compartido por los endpoints de sección,
 * pregunta y opción.
 *
 * Los tres mutan la estructura de una prueba y la regla es idéntica: si ya hay
 * intentos, no se toca. Vive en un solo lugar para que agregar un endpoint nuevo
 * (importar ítems, reordenar en lote) no lo olvide.
 */
trait GuardsFrozenStructure
{
    /**
     * @return JsonResponse|null respuesta 409 si está congelada; null si se puede seguir
     */
    protected function refuseIfFrozen(PsychometricAuthoringService $authoring, ?PsychometricTest $test): ?JsonResponse
    {
        if ($test === null) {
            // Un hijo sin prueba es dato inconsistente, no una petición inválida.
            return $this->error(
                message: 'La prueba asociada no existe.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        try {
            $authoring->assertStructureMutable($test);
        } catch (Throwable $e) {
            return $this->error(
                message: $e->getMessage(),
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        return null;
    }
}
