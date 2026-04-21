<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Http\Controllers\Api\V1\Candidate\Concerns\ResolvesCandidateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidate\ReferenceRequest;
use App\Http\Resources\V1\Profile\ReferenceResource;
use App\Models\CandidateReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class ReferenceController extends Controller
{
    use ResolvesCandidateProfile;

    public function index(Request $request): JsonResponse
    {
        $items = $this->profile($request)
            ->references()
            ->orderBy('sort_order')
            ->get();

        return $this->success(
            message: 'Referencias profesionales.',
            data: ReferenceResource::collection($items),
        );
    }

    public function store(ReferenceRequest $request): JsonResponse
    {
        $ref = $this->profile($request)->references()->create($request->validated());

        return $this->success(
            message: 'Referencia agregada.',
            data: ReferenceResource::make($ref),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(ReferenceRequest $request, CandidateReference $reference): JsonResponse
    {
        $this->ensureOwned($request, $reference->candidate_profile_id);
        $reference->update($request->validated());

        return $this->success(
            message: 'Referencia actualizada.',
            data: ReferenceResource::make($reference->fresh()),
        );
    }

    public function destroy(Request $request, CandidateReference $reference): JsonResponse
    {
        $this->ensureOwned($request, $reference->candidate_profile_id);
        $reference->delete();

        return $this->success(message: 'Referencia eliminada.', status: HttpStatus::HTTP_NO_CONTENT);
    }
}
