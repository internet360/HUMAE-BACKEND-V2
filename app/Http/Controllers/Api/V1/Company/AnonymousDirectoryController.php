<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\AnonymousDirectoryRequest;
use App\Http\Resources\V1\Directory\AnonymousCandidateResource;
use App\Models\CandidateProfile;
use App\Services\DirectorySearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

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

    /**
     * Sirve la foto de un perfil del directorio anónimo.
     *
     * La ruta va firmada y no autenticada porque un `<img src>` no puede
     * adjuntar el Bearer del cliente. La firma es la credencial y caduca; el
     * middleware `signed` la verifica antes de llegar acá.
     *
     * Se vuelve a comprobar la elegibilidad aunque la firma sea válida: entre
     * que se emitió el enlace y se pide la imagen, el candidato pudo vencer su
     * membresía o salir del padrón. Un enlace firmado no debería sobrevivir a
     * la salida de la persona.
     */
    public function photo(string $reference): Response
    {
        $profile = $this->search->visibleProfileByReference($reference);

        $path = $profile?->user?->avatar_path;

        if ($path === null || ! Storage::disk('public')->exists($path)) {
            abort(HttpStatus::HTTP_NOT_FOUND);
        }

        // `response()->file()` y no un stream desde una URL: el archivo vive en
        // el disco del propio servidor y así no se expone su ruta pública, que
        // es lo que lleva el `user_id` dentro.
        $response = response()->file(Storage::disk('public')->path($path));

        // Privada: es la cara de una persona, no un asset de la marca. Sin esto
        // un proxy compartido podría servírsela a alguien más.
        //
        // Con `setPrivate()` y no pasando la cabecera como arreglo: la
        // `BinaryFileResponse` la reescribe y salía `public`.
        $response->setPrivate();
        $response->setMaxAge(300);

        return $response;
    }
}
