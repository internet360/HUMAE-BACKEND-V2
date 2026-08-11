<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Psychometrics;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Psychometric\StaffAttemptResultResource;
use App\Models\CandidateProfile;
use App\Services\PsychometricReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buscador de candidatos y sus rendiciones, para el admin.
 *
 * Gateado por `psychometric.manage`, igual que el resto de
 * `/admin/psychometrics/*`: es la consola del módulo, no el directorio de
 * talento. El reclutador lee los resultados por el directorio
 * (`/directory/candidates/{candidate}/psychometrics`).
 */
class CandidateResultsController extends Controller
{
    public function __construct(
        private readonly PsychometricReportingService $reporting,
    ) {}

    /**
     * Busca candidatos por nombre o correo, con cuántas veces rindieron.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('psychometric.manage');

        $term = $request->string('q')->trim()->toString();

        $candidates = CandidateProfile::query()
            ->when($term !== '', function ($query) use ($term): void {
                $pattern = '%'.$term.'%';
                $query->where(function ($inner) use ($pattern): void {
                    $inner->where('first_name', 'like', $pattern)
                        ->orWhere('last_name', 'like', $pattern)
                        ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $pattern));
                });
            })
            // Sin término se listan los que TIENEN rendiciones. El buscador es
            // para auditar psicométricos: devolver la base entera de candidatos
            // —la mayoría sin un solo intento— sería ruido.
            ->when($term === '', fn ($query) => $query->whereHas('attempts'))
            ->with(['user:id,email'])
            ->withCount('attempts')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(50)
            ->get();

        return $this->success(
            message: 'Candidatos con pruebas psicométricas.',
            data: $candidates->map(fn (CandidateProfile $profile): array => [
                'id' => $profile->id,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'email' => $profile->user?->email,
                'attempt_count' => $profile->attempts_count ?? 0,
            ])->all(),
        );
    }

    /**
     * Todas las rendiciones de un candidato, en cualquier estado.
     *
     * A diferencia de la vista del reclutador —que muestra sólo los intentos
     * calificados— acá se incluyen los `in_progress` y los `expired`. Son
     * precisamente los que hacen falta para responder un "rendí la prueba y no
     * aparece".
     */
    public function show(CandidateProfile $candidate): JsonResponse
    {
        $this->authorize('psychometric.manage');

        $candidate->load('user:id,email');

        return $this->success(
            message: 'Rendiciones del candidato.',
            data: [
                'candidate' => [
                    'id' => $candidate->id,
                    'first_name' => $candidate->first_name,
                    'last_name' => $candidate->last_name,
                    'email' => $candidate->user?->email,
                ],
                'attempts' => StaffAttemptResultResource::collection(
                    $this->reporting->allAttempts($candidate),
                ),
            ],
        );
    }
}
