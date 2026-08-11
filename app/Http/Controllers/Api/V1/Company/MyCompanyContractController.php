<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Http\Concerns\ResolvesMyCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\SignCompanyContractRequest;
use App\Http\Resources\V1\Companies\CompanyContractResource;
use App\Models\User;
use App\Services\CompanyContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Firma y consulta del contrato de prestación de servicios de la empresa cliente.
 *
 * Paridad con el flujo de RED1A1 (`POST /oauth/users/cincel`): el cliente manda
 * firma trazada + identificación + selfie, el backend arma el PDF del contrato
 * con esa firma y lo sella con la constancia NOM-151 de CINCEL.
 *
 * Diferencia de alcance: en RED1A1 el contrato es del asesor (por usuario); acá
 * es de la empresa, así que lo firma un representante y obliga a la persona
 * moral.
 */
class MyCompanyContractController extends Controller
{
    use ResolvesMyCompany;

    public function __construct(
        private readonly CompanyContractService $contracts,
    ) {}

    /**
     * Estado del contrato vigente. Es lo que consulta el gate del frontend.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->mayActAsCompany($user)) {
            return $this->error('No tienes acceso a este recurso.', status: HttpStatus::HTTP_FORBIDDEN);
        }

        [$company, $member] = $this->resolveCompany($user, ['latestContract.signedBy']);

        if ($company === null) {
            return $this->error(
                'Tu cuenta no está vinculada a una empresa.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        $contract = $company->latestContract;

        return $this->success(
            message: $contract === null ? 'La empresa no ha firmado el contrato.' : 'Contrato de la empresa.',
            data: $contract === null ? null : CompanyContractResource::make($contract),
            meta: [
                'is_signed' => $contract !== null,
                'can_sign' => $contract === null && $this->canEdit($user, $member),
                // Términos que se firmarían ahora. El wizard los muestra con las
                // cifras reales en vez de reescribir el contrato en el frontend.
                'pending_terms' => $contract === null ? $this->contracts->currentTerms() : null,
                'preview_url' => $contract === null
                    ? route('me.company.contract.preview')
                    : null,
            ],
        );
    }

    /**
     * Borrador del contrato con los términos vigentes, sin firma.
     *
     * Es el documento que el wizard muestra antes de pedir la aceptación: sale
     * del mismo Blade que el contrato definitivo, así que lo leído y lo firmado
     * no se pueden desincronizar.
     */
    public function preview(Request $request): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->mayActAsCompany($user)) {
            return $this->error('No tienes acceso a este recurso.', status: HttpStatus::HTTP_FORBIDDEN);
        }

        [$company, $member] = $this->resolveCompany($user);

        if ($company === null) {
            return $this->error(
                'Tu cuenta no está vinculada a una empresa.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        if (! $this->canEdit($user, $member)) {
            return $this->error(
                'Solo quien tenga rol de owner o manager puede consultar el contrato por firmar.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        try {
            $pdf = $this->contracts->previewPdf($company, $user);
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                'No pudimos generar la vista previa del contrato.',
                status: HttpStatus::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response($pdf, HttpStatus::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="contrato-humae-borrador.pdf"',
        ]);
    }

    /**
     * Firma el contrato. Idempotente por diseño: si ya hay contrato responde 409
     * en lugar de emitir un segundo, porque volver a firmar no es un reintento
     * sino una renegociación, y esa la abre HUMAE.
     */
    public function store(SignCompanyContractRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->mayActAsCompany($user)) {
            return $this->error('No tienes acceso a este recurso.', status: HttpStatus::HTTP_FORBIDDEN);
        }

        [$company, $member] = $this->resolveCompany($user, ['latestContract']);

        if ($company === null) {
            return $this->error(
                'Tu cuenta no está vinculada a una empresa.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        if (! $this->canEdit($user, $member)) {
            return $this->error(
                'Solo quien tenga rol de owner o manager puede firmar el contrato de la empresa.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        if ($company->latestContract !== null) {
            return $this->error(
                'Esta empresa ya tiene un contrato firmado.',
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        /** @var UploadedFile $signature */
        $signature = $request->file('signature');
        /** @var UploadedFile $identity */
        $identity = $request->file('identity');
        /** @var UploadedFile $selfie */
        $selfie = $request->file('selfie');

        $now = Carbon::now();

        try {
            $contract = $this->contracts->sign(
                company: $company,
                signer: $user,
                files: [
                    'signature' => $signature,
                    'identity' => $identity,
                    'selfie' => $selfie,
                ],
                meta: [
                    'signer_title' => (string) $request->validated('signer_title'),
                    'privacy_accepted_at' => $now,
                    'terms_accepted_at' => $now,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            );
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                'No pudimos emitir tu contrato. Intenta más tarde o escríbenos.',
                status: HttpStatus::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $contract->load('signedBy');

        return $this->success(
            message: $contract->isTimestamped()
                ? 'Contrato firmado y sellado.'
                : 'Contrato firmado. La constancia de integridad se emitirá en breve.',
            data: CompanyContractResource::make($contract),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    /**
     * Descarga del PDF firmado. Sirve el archivo almacenado, nunca uno
     * regenerado: DomPDF escribe CreationDate en los metadatos, así que un PDF
     * reconstruido tendría otro hash y dejaría de cuadrar con la constancia.
     */
    public function download(Request $request): StreamedResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->mayActAsCompany($user)) {
            return $this->error('No tienes acceso a este recurso.', status: HttpStatus::HTTP_FORBIDDEN);
        }

        [$company] = $this->resolveCompany($user, ['latestContract']);

        $contract = $company?->latestContract;

        if ($contract === null) {
            return $this->error('Tu empresa no tiene un contrato firmado.', status: HttpStatus::HTTP_NOT_FOUND);
        }

        if (! Storage::disk('local')->exists($contract->pdf_path)) {
            return $this->error('El archivo del contrato no está disponible.', status: HttpStatus::HTTP_NOT_FOUND);
        }

        return Storage::disk('local')->download(
            $contract->pdf_path,
            'contrato-humae-'.$contract->folio.'.pdf',
        );
    }
}
