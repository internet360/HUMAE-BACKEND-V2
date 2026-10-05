<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InvoiceRequestStatus;
use App\Exceptions\InvalidInvoiceRequestTransitionException;
use App\Helpers\LocalFileStorage;
use App\Models\InvoiceRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * CFDI files are legal records on the private `local` disk. Nothing here (nor
 * anywhere else in the app) deletes them once stored, and an issued request can
 * never have its files replaced.
 */
class InvoiceFileService
{
    private const DISK = 'local';

    /** @var array<string, string> */
    private const CONTENT_TYPES = ['pdf' => 'application/pdf', 'xml' => 'application/xml'];

    public function __construct(private readonly LocalFileStorage $storage) {}

    /**
     * The only path to `issued`: locks the request, stores both files, records
     * the paths and the stamp UUID. If anything fails the stored files are
     * removed again (they are not yet referenced by any row).
     *
     * @return InvoiceRequestStatus the status the request had before
     *
     * @throws InvalidInvoiceRequestTransitionException when the request cannot be issued
     */
    public function issue(InvoiceRequest $request, UploadedFile $pdf, UploadedFile $xml, string $uuid): InvoiceRequestStatus
    {
        $stored = [];

        try {
            return DB::transaction(function () use ($request, $pdf, $xml, $uuid, &$stored): InvoiceRequestStatus {
                $locked = InvoiceRequest::query()->lockForUpdate()->findOrFail($request->id);
                $from = $locked->status;

                if (! in_array($from, [InvoiceRequestStatus::Requested, InvoiceRequestStatus::InProgress], true)) {
                    throw new InvalidInvoiceRequestTransitionException($from, InvoiceRequestStatus::Issued);
                }

                $folder = 'invoices/'.now()->format('Y').'/'.$locked->id;
                $pdfPath = $stored[] = $this->storage->upload($pdf, $folder, ['disk' => self::DISK])['public_id'];
                $xmlPath = $stored[] = $this->storage->upload($xml, $folder, ['disk' => self::DISK])['public_id'];

                $locked->update([
                    'status' => InvoiceRequestStatus::Issued,
                    'pdf_path' => $pdfPath,
                    'xml_path' => $xmlPath,
                    'cfdi_uuid' => $uuid,
                    'issued_at' => now(),
                ]);

                $request->setRawAttributes($locked->getAttributes(), true);

                return $from;
            });
        } catch (Throwable $e) {
            foreach ($stored as $path) {
                $this->storage->destroy($path, self::DISK);
            }

            throw $e;
        }
    }

    /** Null when the request has no such file (yet) or it is missing on disk. */
    public function download(InvoiceRequest $request, string $kind): ?StreamedResponse
    {
        $path = match ($kind) {
            'pdf' => $request->pdf_path,
            'xml' => $request->xml_path,
            default => null,
        };

        if ($path === null || $path === '' || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->download(
            $path,
            'CFDI-'.($request->cfdi_uuid ?? $request->id).'.'.$kind,
            [
                'Content-Type' => self::CONTENT_TYPES[$kind],
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
