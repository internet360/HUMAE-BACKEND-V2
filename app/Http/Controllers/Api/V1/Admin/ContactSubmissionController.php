<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Admin\ContactSubmissionResource;
use App\Models\ContactSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactSubmissionController extends Controller
{
    /**
     * Minimal admin visibility so leads are reachable in-product, not only by
     * email — the endpoint route itself is guarded by RoleMiddleware:admin.
     */
    public function index(Request $request): JsonResponse
    {
        $statusFilter = $request->string('status')->toString();
        $typeFilter = $request->string('type')->toString();

        $submissions = ContactSubmission::query()
            ->with('assignee')
            ->when($statusFilter !== '', fn ($q) => $q->where('status', $statusFilter))
            ->when($typeFilter !== '', fn ($q) => $q->where('type', $typeFilter))
            ->orderByDesc('id')
            ->paginate(20);

        return $this->success(
            message: 'Solicitudes de contacto.',
            data: ContactSubmissionResource::collection($submissions),
            meta: [
                'pagination' => [
                    'current_page' => $submissions->currentPage(),
                    'per_page' => $submissions->perPage(),
                    'total' => $submissions->total(),
                    'last_page' => $submissions->lastPage(),
                ],
            ],
        );
    }
}
