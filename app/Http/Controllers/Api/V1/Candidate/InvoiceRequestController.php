<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Exceptions\PaymentNotEligibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidate\StoreInvoiceRequestRequest;
use App\Http\Resources\V1\InvoiceRequestResource;
use App\Http\Resources\V1\PaymentResource;
use App\Jobs\NotifyBillingOfInvoiceRequestJob;
use App\Models\InvoiceRequest;
use App\Models\User;
use App\Services\InvoiceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

class InvoiceRequestController extends Controller
{
    /** @var array<string, string> */
    private const REASON_MESSAGES = [
        'not_found' => 'Alguno de los pagos seleccionados no existe.',
        'not_eligible' => 'Alguno de los pagos seleccionados no se puede facturar (reembolsado, en disputa o sin confirmar).',
        'claimed' => 'Alguno de los pagos seleccionados ya tiene una solicitud de factura activa.',
        'deadline' => 'Alguno de los pagos seleccionados está fuera del plazo para solicitar factura.',
    ];

    public function __construct(private readonly InvoiceRequestService $service) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $requests = InvoiceRequest::query()
            ->where('user_id', $user->id)
            ->with('payments')
            ->orderByDesc('id')
            ->paginate(20);

        return $this->success(
            message: 'Solicitudes de factura.',
            data: InvoiceRequestResource::collection($requests),
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

    public function eligiblePayments(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->success(
            message: 'Pagos disponibles para facturar.',
            data: PaymentResource::collection($this->service->eligiblePayments($user)->load('currency')),
        );
    }

    public function show(InvoiceRequest $invoiceRequest): JsonResponse
    {
        $this->authorize('view', $invoiceRequest);

        return $this->success(
            message: 'Solicitud de factura.',
            data: InvoiceRequestResource::make($invoiceRequest->load('payments')),
        );
    }

    public function store(StoreInvoiceRequestRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $invoiceRequest = $this->service->claim($user, $request->paymentIds(), $request->fiscal());
        } catch (PaymentNotEligibleException $e) {
            return $this->error(
                message: 'La validación falló.',
                errors: ['payment_ids' => [self::REASON_MESSAGES[$e->reason] ?? self::REASON_MESSAGES['not_eligible']]],
                status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // The request is already saved: telling billing is best-effort. With a
        // sync queue the job runs (and fails) inline; the job's own `failed()`
        // hook logged it, so the user still gets their 201. Nothing here may
        // log the fiscal data.
        try {
            NotifyBillingOfInvoiceRequestJob::dispatch($invoiceRequest->id)->afterCommit();
        } catch (Throwable $e) {
            Log::warning('Billing notification dispatch raised for an invoice request.', [
                'invoice_request_id' => $invoiceRequest->id,
                'exception' => $e::class,
            ]);
        }

        return $this->success(
            message: 'Solicitud de factura creada.',
            data: InvoiceRequestResource::make($invoiceRequest),
            status: HttpStatus::HTTP_CREATED,
        );
    }
}
