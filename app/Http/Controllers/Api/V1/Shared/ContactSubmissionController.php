<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\CreateContactSubmissionRequest;
use App\Services\ContactSubmissionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class ContactSubmissionController extends Controller
{
    public function __construct(private readonly ContactSubmissionService $submissions) {}

    /**
     * Public, unauthenticated lead-capture endpoint. Never echoes the stored
     * submission back: there is no reason for a public endpoint to reflect
     * persisted data, and doing so would confirm to a prober what was saved.
     */
    public function store(CreateContactSubmissionRequest $request): JsonResponse
    {
        /** @var array{name: string, email: string, phone?: string|null, company?: string|null, subject?: string|null, message: string, type?: string|null, source?: string|null} $data */
        $data = $request->validated();

        $this->submissions->submit($data, $request->ip(), $request->userAgent());

        return $this->success(
            message: 'Recibimos tu mensaje. Nuestro equipo te contactará pronto.',
            status: HttpStatus::HTTP_CREATED,
        );
    }
}
