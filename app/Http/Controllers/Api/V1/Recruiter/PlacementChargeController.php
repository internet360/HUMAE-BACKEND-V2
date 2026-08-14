<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Recruiter;

use App\Enums\PlacementChargeState;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Pipeline\PlacementChargeResource;
use App\Models\PlacementCharge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cargos por colocación devengados. Sólo HUMAE.
 *
 * Lectura y nada más en esta fase: el cargo lo crea `HireService` al cerrar la
 * colocación, y facturar o cobrar pasa fuera del sistema. Un endpoint de
 * escritura aquí invitaría a corregir montos a mano, que es exactamente lo que
 * un registro contable no debe permitir.
 */
class PlacementChargeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PlacementCharge::class);

        $states = array_map(fn (PlacementChargeState $s) => $s->value, PlacementChargeState::cases());

        $validated = $request->validate([
            'state' => ['sometimes', Rule::in($states)],
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
        ]);

        // Explícito: HUMAE no es tenant y necesita ver la cartera completa.
        //
        // Las tres relaciones se precargan porque la lista las muestra en cada
        // fila: sin esto son 3N consultas para pintar veinte cargos.
        $query = PlacementCharge::acrossCompanies()
            ->with(['company', 'vacancy', 'currency']);

        if (isset($validated['state'])) {
            $query->where('state', $validated['state']);
        }
        if (isset($validated['company_id'])) {
            $query->where('company_id', $validated['company_id']);
        }

        $paginator = $query->orderByDesc('accrued_at')->paginate(20);

        return $this->success(
            message: 'Cargos por colocación.',
            data: PlacementChargeResource::collection($paginator),
            meta: [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'accrued_total' => (float) $query->clone()->sum('amount'),
            ],
        );
    }
}
