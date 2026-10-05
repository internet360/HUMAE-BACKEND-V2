<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InvoiceRequestStatus;
use App\Exceptions\InvalidInvoiceRequestTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListInvoiceRequestsRequest;
use App\Http\Requests\Admin\UpdateInvoiceRequestNotesRequest;
use App\Http\Requests\Admin\UpdateInvoiceRequestStatusRequest;
use App\Http\Resources\V1\Admin\AdminInvoiceRequestResource;
use App\Models\InvoiceRequest;
use App\Models\User;
use App\Services\InvoiceRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Admin side of the CFDI requests. Authorization is the Spatie permission
 * `invoices.manage`, checked in each FormRequest.
 *
 * Audit entries never carry the RFC, the legal name or the notes' content.
 */
class InvoiceRequestController extends Controller
{
    public function __construct(private readonly InvoiceRequestService $service) {}

    public function index(ListInvoiceRequestsRequest $request): JsonResponse
    {
        $search = trim($request->string('q')->toString());

        $requests = InvoiceRequest::query()
            ->with('user')
            ->withCount('payments')
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('created_at', '>=', $request->date('from')?->startOfDay()))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('created_at', '<=', $request->date('to')?->endOfDay()))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(function (Builder $inner) use ($like, $search): void {
                    // The RFC is matched exactly (never LIKE) so a partial guess cannot enumerate it.
                    $inner->where('rfc', strtoupper($search))
                        ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like));
                });
            })
            ->orderByDesc('id')
            ->paginate(20);

        return $this->success(
            message: 'Solicitudes de factura.',
            data: AdminInvoiceRequestResource::collection($requests),
            meta: [
                'pagination' => [
                    'current_page' => $requests->currentPage(),
                    'per_page' => $requests->perPage(),
                    'total' => $requests->total(),
                    'last_page' => $requests->lastPage(),
                ],
            ],
        );
    }

    public function show(InvoiceRequest $invoiceRequest): JsonResponse
    {
        $this->authorize('invoices.manage');

        return $this->detail('Solicitud de factura.', $invoiceRequest);
    }

    public function updateStatus(UpdateInvoiceRequestStatusRequest $request, InvoiceRequest $invoiceRequest): JsonResponse
    {
        $to = InvoiceRequestStatus::from($request->string('status')->toString());

        try {
            $from = $this->service->transition($invoiceRequest, $to, $request->input('reason'));
        } catch (InvalidInvoiceRequestTransitionException $e) {
            return $this->error(
                message: 'La validación falló.',
                errors: ['status' => ["No se puede pasar de «{$e->from->label()}» a «{$e->to->label()}»."]],
                status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var User $actor */
        $actor = $request->user();

        activity('invoice-requests')
            ->performedOn($invoiceRequest)
            ->causedBy($actor)
            ->withProperties([
                'invoice_request_id' => $invoiceRequest->id,
                'from' => $from->value,
                'to' => $to->value,
                'ip' => $request->ip(),
            ])
            ->log('Cambió el estado de una solicitud de factura.');

        return $this->detail('Estado actualizado.', $invoiceRequest);
    }

    public function updateNotes(UpdateInvoiceRequestNotesRequest $request, InvoiceRequest $invoiceRequest): JsonResponse
    {
        $notes = $request->input('admin_notes');
        $invoiceRequest->update(['admin_notes' => is_string($notes) && trim($notes) !== '' ? $notes : null]);

        /** @var User $actor */
        $actor = $request->user();

        activity('invoice-requests')
            ->performedOn($invoiceRequest)
            ->causedBy($actor)
            ->withProperties(['invoice_request_id' => $invoiceRequest->id, 'ip' => $request->ip()])
            ->log('Actualizó las notas de una solicitud de factura.');

        return $this->detail('Notas actualizadas.', $invoiceRequest);
    }

    private function detail(string $message, InvoiceRequest $invoiceRequest): JsonResponse
    {
        return $this->success(
            message: $message,
            data: new AdminInvoiceRequestResource($invoiceRequest->load(['user', 'payments']), true),
        );
    }
}
