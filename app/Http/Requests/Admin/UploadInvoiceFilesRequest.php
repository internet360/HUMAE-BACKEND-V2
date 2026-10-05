<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\InvoiceRequest;
use App\Support\Cfdi\CfdiXmlInspector;
use App\Support\Cfdi\InvalidCfdiXmlException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Issuing a CFDI: both files are mandatory and the XML is cross-checked
 * against the request (receptor RFC) before anything is stored.
 */
class UploadInvoiceFilesRequest extends FormRequest
{
    /** @var array{rfc: string, uuid: string}|null */
    private ?array $cfdi = null;

    public function authorize(): bool
    {
        return $this->user()?->can('invoices.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pdf' => ['required', 'file', 'extensions:pdf', 'mimes:pdf', 'max:5120'],
            'xml' => ['required', 'file', 'extensions:xml', 'mimetypes:text/xml,application/xml', 'max:1024'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['pdf' => 'PDF', 'xml' => 'XML'];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $xml = $this->file('xml');

            if ($validator->errors()->isNotEmpty() || ! $xml instanceof UploadedFile) {
                return;
            }

            try {
                $cfdi = app(CfdiXmlInspector::class)->inspect((string) file_get_contents($xml->getRealPath()));
            } catch (InvalidCfdiXmlException $e) {
                $validator->errors()->add('xml', $e->getMessage());

                return;
            }

            $invoiceRequest = $this->route('invoiceRequest');

            // The RFC is deliberately not echoed in the message (personal data).
            if ($invoiceRequest instanceof InvoiceRequest && ! hash_equals(strtoupper($invoiceRequest->rfc), $cfdi['rfc'])) {
                $validator->errors()->add('xml', 'El RFC del receptor del XML no coincide con el de la solicitud.');

                return;
            }

            $this->cfdi = $cfdi;
        }];
    }

    /** @return array{rfc: string, uuid: string} */
    public function cfdi(): array
    {
        return $this->cfdi ?? throw new \LogicException('The request was not validated.');
    }

    public function pdfFile(): UploadedFile
    {
        $file = $this->file('pdf');
        assert($file instanceof UploadedFile);

        return $file;
    }

    public function xmlFile(): UploadedFile
    {
        $file = $this->file('xml');
        assert($file instanceof UploadedFile);

        return $file;
    }
}
