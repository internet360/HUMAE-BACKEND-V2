<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\InvoiceRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceRequestStatusRequest extends FormRequest
{
    /** Statuses that close or contest a fiscal document and must be justified. */
    private const REASON_REQUIRED = ['rejected', 'cancellation_pending', 'cancelled'];

    public function authorize(): bool
    {
        return $this->user()?->can('invoices.manage') ?? false;
    }

    /**
     * `issued` is excluded on purpose: a request is issued only by uploading
     * the PDF + XML, never by a plain status change.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(array_map(
                static fn (InvoiceRequestStatus $s): string => $s->value,
                array_filter(InvoiceRequestStatus::cases(), static fn (InvoiceRequestStatus $s): bool => $s !== InvoiceRequestStatus::Issued),
            ))],
            'reason' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn (): bool => in_array($this->input('status'), self::REASON_REQUIRED, true))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.in' => 'El estado no es válido. Una solicitud sólo pasa a «Emitida» al subir la factura (PDF y XML).',
            'reason.required' => 'Indica el motivo del cambio de estado.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['status' => 'estado', 'reason' => 'motivo'];
    }
}
