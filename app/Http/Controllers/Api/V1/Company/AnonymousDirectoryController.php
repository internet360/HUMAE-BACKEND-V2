<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\AnonymousDirectoryRequest;
use App\Http\Resources\V1\Directory\AnonymousCandidateResource;
use App\Models\CandidateProfile;
use App\Services\DirectorySearchService;
use Illuminate\Http\JsonResponse;

/**
 * Navegación anónima del talento, para la empresa cliente.
 *
 * Endpoint aparte del directorio interno (`/directory/candidates`) y no una
 * relajación suya. Son dos superficies con dos reglas distintas y conviene que
 * el enrutador lo diga: el directorio interno sigue cerrado a recruiter/admin y
 * `tests/Feature/Security/CompanyUserDirectoryAccessTest.php` lo vigila.
 *
 * Sólo listado. El detalle llega con la selección de candidatos, cuando esté
 * definido qué necesita el panel para decidir; hasta entonces no se abre una
 * superficie que nadie consume.
 */
class AnonymousDirectoryController extends Controller
{
    public function __construct(
        private readonly DirectorySearchService $search,
    ) {}

    public function index(AnonymousDirectoryRequest $request): JsonResponse
    {
        $this->authorize('viewAnonymousDirectory', CandidateProfile::class);

        $paginator = $this->search->searchAnonymous($request);

        return $this->success(
            message: 'Talento disponible.',
            data: AnonymousCandidateResource::collection($paginator),
            meta: [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        );
    }
}
