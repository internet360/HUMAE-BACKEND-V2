<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Http\Controllers\Api\V1\Candidate\Concerns\ResolvesCandidateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidate\CertificationRequest;
use App\Http\Resources\V1\Profile\CertificationResource;
use App\Models\CandidateCertification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class CertificationController extends Controller
{
    use ResolvesCandidateProfile;

    public function index(Request $request): JsonResponse
    {
        $items = $this->profile($request)
            ->certifications()
            ->orderBy('sort_order')
            ->orderByDesc('issued_at')
            ->get();

        return $this->success(
            message: 'Certificaciones.',
            data: CertificationResource::collection($items),
        );
    }

    public function store(CertificationRequest $request): JsonResponse
    {
        $cert = $this->profile($request)->certifications()->create($request->validated());

        return $this->success(
            message: 'Certificación agregada.',
            data: CertificationResource::make($cert),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(CertificationRequest $request, CandidateCertification $certification): JsonResponse
    {
        $this->ensureOwned($request, $certification->candidate_profile_id);
        $certification->update($request->validated());

        return $this->success(
            message: 'Certificación actualizada.',
            data: CertificationResource::make($certification->fresh()),
        );
    }

    public function destroy(Request $request, CandidateCertification $certification): JsonResponse
    {
        $this->ensureOwned($request, $certification->candidate_profile_id);
        $certification->delete();

        return $this->success(message: 'Certificación eliminada.', status: HttpStatus::HTTP_NO_CONTENT);
    }
}
