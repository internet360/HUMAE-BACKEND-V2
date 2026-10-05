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
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
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
            ->when($request->filled('from'), fn (Builder $q) => $q->where('created_at', '>=', $this->billingDayBoundary($request->string('from')->toString(), endOfDay: false)))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('created_at', '<=', $this->billingDayBoundary($request->string('to')->toString(), endOfDay: true)))
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

        $reason = is_string($request->input('reason')) ? trim($request->input('reason')) : null;
        $reason = $reason === '' ? null : $reason;

        try {
            $from = $this->service->transition($invoiceRequest, $to, $reason);
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
            ->withProperties(array_filter([
                'invoice_request_id' => $invoiceRequest->id,
                'from' => $from->value,
                'to' => $to->value,
                // Free text typed by the admin; it is not PII (never the RFC or legal name).
                'reason' => $reason,
                'ip' => $request->ip(),
            ], static fn (mixed $value): bool => $value !== null))
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

    /**
     * A calendar day typed by the admin is a day in the billing timezone; the
     * column stores UTC, so the boundary is converted before comparing.
     */
    private function billingDayBoundary(string $date, bool $endOfDay): CarbonInterface
    {
        $day = Carbon::parse($date, (string) config('billing.timezone'));

        return ($endOfDay ? $day->endOfDay() : $day->startOfDay())->setTimezone('UTC');
    }

    private function detail(string $message, InvoiceRequest $invoiceRequest): JsonResponse
    {
        return $this->success(
            message: $message,
            data: new AdminInvoiceRequestResource($invoiceRequest->load(['user', 'payments']), true),
        );
    }
}
