<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Enums\DocumentType;
use App\Helpers\LocalFileStorage;
use App\Http\Controllers\Api\V1\Candidate\Concerns\ResolvesCandidateProfile;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Profile\DocumentResource;
use App\Models\CandidateDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentController extends Controller
{
    use ResolvesCandidateProfile;

    public function __construct(
        private readonly LocalFileStorage $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->profile($request)
            ->documents()
            ->where('is_internal', false)
            ->orderByDesc('uploaded_at')
            ->get();

        return $this->success(
            message: 'Documentos del candidato.',
            data: DocumentResource::collection($items),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $types = array_map(fn (DocumentType $t) => $t->value, DocumentType::cases());

        $validated = $request->validate([
            'type' => ['required', Rule::in($types)],
            'title' => ['required', 'string', 'max:200'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:10240'],
        ]);

        $file = $request->file('file');
        if ($file === null || ! $file->isValid()) {
            return $this->error('Archivo inválido.', status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY);
        }

        $profile = $this->profile($request);

        try {
            $uploaded = $this->storage->upload($file, 'documents/'.$profile->id, [
                'disk' => 'local',
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                'No pudimos subir el archivo. Intenta más tarde.',
                status: HttpStatus::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $document = $profile->documents()->create([
            'type' => $validated['type'],
            'title' => $validated['title'],
            'file_url' => '', // relleno después con la ruta autenticada (requiere el ID)
            'file_provider' => 'local',
            'file_public_id' => $uploaded['public_id'],
            'mime_type' => $uploaded['mime_type'],
            'file_size_bytes' => $uploaded['size'],
            'is_internal' => false,
            'uploaded_at' => now(),
        ]);

        $document->forceFill([
            'file_url' => route('me.profile.documents.download', ['document' => $document->id]),
        ])->save();

        return $this->success(
            message: 'Documento cargado.',
            data: DocumentResource::make($document),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function destroy(Request $request, CandidateDocument $document): JsonResponse
    {
        $this->ensureOwned($request, $document->candidate_profile_id);

        if ($document->file_public_id !== null) {
            $this->storage->destroy($document->file_public_id, 'local');
        }

        $document->delete();

        return $this->success(message: 'Documento eliminado.', status: HttpStatus::HTTP_NO_CONTENT);
    }

    public function download(Request $request, CandidateDocument $document): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $this->ensureOwned($request, $document->candidate_profile_id);

        if ($document->file_public_id === null || ! Storage::disk('local')->exists($document->file_public_id)) {
            return $this->error('Archivo no disponible.', status: HttpStatus::HTTP_NOT_FOUND);
        }

        $extension = pathinfo($document->file_public_id, PATHINFO_EXTENSION);
        $downloadName = trim($document->title).($extension !== '' ? '.'.$extension : '');

        return Storage::disk('local')->download($document->file_public_id, $downloadName);
    }
}
