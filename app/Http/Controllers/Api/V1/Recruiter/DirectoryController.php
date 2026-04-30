<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Recruiter;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Directory\DirectoryCandidateDetailResource;
use App\Http\Resources\V1\Directory\DirectoryCandidateResource;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\DirectoryFavorite;
use App\Models\User;
use App\Services\CvGenerationService;
use App\Services\DirectorySearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DirectoryController extends Controller
{
    public function __construct(
        private readonly DirectorySearchService $search,
        private readonly CvGenerationService $cv,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeRecruiter($request);

        $paginator = $this->search->search($request);

        $ids = array_values(array_map(
            static fn (CandidateProfile $p): int => (int) $p->id,
            $paginator->items(),
        ));

        $this->attachFavoriteIds($request, $ids);

        return $this->success(
            message: 'Directorio de candidatos.',
            data: DirectoryCandidateResource::collection($paginator),
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

    public function show(Request $request, CandidateProfile $candidate): JsonResponse
    {
        $this->authorizeRecruiter($request);

        $candidate->load([
            'user',
            'experiences' => fn ($q) => $q->orderByDesc('start_date'),
            'educations' => fn ($q) => $q->orderByDesc('end_date'),
            'courses',
            'certifications',
            'references',
            'documents' => fn ($q) => $q->where('is_internal', false),
            'skills',
            'languages',
            'functionalAreas',
        ]);

        $this->attachFavoriteIds($request, [$candidate->id]);

        return $this->success(
            message: 'Expediente.',
            data: DirectoryCandidateDetailResource::make($candidate),
        );
    }

    public function toggleFavorite(Request $request, CandidateProfile $candidate): JsonResponse
    {
        $this->authorizeRecruiter($request);

        /** @var User $user */
        $user = $request->user();

        $existing = DirectoryFavorite::where('recruiter_id', $user->id)
            ->where('candidate_profile_id', $candidate->id)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return $this->success(
                message: 'Favorito removido.',
                data: ['is_favorite' => false],
            );
        }

        DirectoryFavorite::create([
            'recruiter_id' => $user->id,
            'candidate_profile_id' => $candidate->id,
        ]);

        return $this->success(
            message: 'Favorito agregado.',
            data: ['is_favorite' => true],
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function downloadCv(Request $request, CandidateProfile $candidate): Response
    {
        $this->authorizeRecruiter($request);

        $user = $candidate->user;
        if ($user === null) {
            abort(HttpStatus::HTTP_NOT_FOUND);
        }

        $result = $this->cv->generate($user);

        return response($result['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function downloadDocument(
        Request $request,
        CandidateProfile $candidate,
        CandidateDocument $document,
    ): BinaryFileResponse|StreamedResponse|JsonResponse {
        $this->authorizeRecruiter($request);

        if ($document->candidate_profile_id !== $candidate->id || $document->is_internal) {
            abort(HttpStatus::HTTP_NOT_FOUND);
        }

        if ($document->file_public_id === null
            || ! Storage::disk('local')->exists($document->file_public_id)
        ) {
            return $this->error('Archivo no disponible.', status: HttpStatus::HTTP_NOT_FOUND);
        }

        $extension = pathinfo($document->file_public_id, PATHINFO_EXTENSION);
        $downloadName = trim((string) $document->title).($extension !== '' ? '.'.$extension : '');

        return Storage::disk('local')->download($document->file_public_id, $downloadName);
    }

    private function authorizeRecruiter(Request $request): void
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            abort(HttpStatus::HTTP_UNAUTHORIZED);
        }

        if (! $user->hasAnyRole([UserRole::Recruiter->value, UserRole::Admin->value])) {
            abort(HttpStatus::HTTP_FORBIDDEN, 'Acceso restringido al equipo HUMAE.');
        }
    }

    /**
     * Carga los IDs favoritos del recruiter actual y los inyecta en la request
     * para que los Resources puedan marcar `is_favorite` sin queries N+1.
     *
     * @param  list<int>  $candidateIds
     */
    private function attachFavoriteIds(Request $request, array $candidateIds): void
    {
        if ($candidateIds === []) {
            $request->attributes->set('directory.favorite_ids', []);

            return;
        }

        /** @var User $user */
        $user = $request->user();

        $favorites = DirectoryFavorite::where('recruiter_id', $user->id)
            ->whereIn('candidate_profile_id', $candidateIds)
            ->pluck('candidate_profile_id')
            ->all();

        $request->attributes->set('directory.favorite_ids', $favorites);
    }
}
