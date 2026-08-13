<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Http\Concerns\ResolvesMyCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\SignVacancyAddendumRequest;
use App\Http\Resources\V1\Companies\CompanyContractResource;
use App\Models\CompanyContract;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\CompanyContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

/**
 * Adenda de honorarios para una vacante concreta.
 *
 * Existe por una regla de negocio: lo que HUMAE factura tiene que estar
 * respaldado siempre por algo que la empresa firmó. Cuando el equipo quiere
 * cobrar distinto en una vacante difícil, no basta con escribir el porcentaje
 * en un campo interno — hay que emitir este documento y que lo firmen.
 *
 * Reutiliza entero el mecanismo del contrato maestro donde eso hace al
 * instrumento: mismo PDF, misma constancia NOM-151, mismo folio consecutivo,
 * misma firma trazada. Un camino más ligero «porque es sólo una adenda» habría
 * producido un documento más débil justo donde se decide el dinero.
 *
 * Lo único que se recorta es el trámite. Quien ya firmó el maestro está
 * acreditado —su INE y su selfie están resguardadas y son las mismas— así que
 * el wizard no se las vuelve a pedir y la adenda se firma leyéndola y
 * trazando la firma. Si firma otra persona, se le pide todo igual que en el
 * maestro: de ella nadie acreditó nada.
 */
class VacancyContractAddendumController extends Controller
{
    use ResolvesMyCompany;

    public function __construct(
        private readonly CompanyContractService $contracts,
    ) {}

    /**
     * Estado de la adenda de esta vacante: la firmada, o los términos que se
     * firmarían.
     */
    public function show(Request $request, Vacancy $vacancy): JsonResponse
    {
        $this->authorize('view', $vacancy);

        /** @var User $user */
        $user = $request->user();

        $addendum = CompanyContract::addendumFor($vacancy->id);

        $pending = null;
        if ($addendum === null) {
            try {
                $pending = $this->contracts->addendumTerms($vacancy);
            } catch (RuntimeException) {
                // La vacante no tiene honorarios propios: no hay nada que
                // firmar y se factura con el contrato maestro. No es un error.
                $pending = null;
            }
        }

        $master = CompanyContract::masterFor($vacancy->company_id);

        [$company, $member] = $this->resolveCompany($user);

        // Un reclutador ve esta pantalla —necesita saber qué le va a proponer al
        // cliente— pero no firma por él: la adenda obliga a la empresa.
        $canSign = $company !== null
            && $company->id === $vacancy->company_id
            && $this->canEdit($user, $member)
            && $master !== null
            && $addendum === null
            && $pending !== null;

        // Quién ya está acreditado decide cuántos pasos pide el wizard. Se
        // resuelve acá y no en el cliente porque es la misma regla que aplica
        // `SignVacancyAddendumRequest`, y dos copias de una regla se separan.
        $accreditedBy = $master !== null && $master->signed_by_user_id === $user->id
            ? $master
            : null;

        return $this->success(
            message: 'Adenda de honorarios de la vacante.',
            data: $addendum !== null ? CompanyContractResource::make($addendum) : null,
            meta: [
                'pending_terms' => $pending,
                'master_contract_signed' => $master !== null,
                'can_sign' => $canSign,
                'identity_on_file' => $accreditedBy !== null,
                'signer_title' => $accreditedBy?->signer_title,
            ],
        );
    }

    /**
     * Borrador de la adenda en PDF.
     *
     * Sin esto la empresa firmaría a ciegas: la adenda se distingue del
     * contrato maestro en un solo número —los honorarios— y es exactamente el
     * que hay que poder leer antes de aceptar. Sale del mismo Blade que la
     * versión firmada, así que lo leído y lo firmado no pueden desincronizarse.
     */
    public function preview(Request $request, Vacancy $vacancy): Response|JsonResponse
    {
        $this->authorize('view', $vacancy);

        /** @var User $user */
        $user = $request->user();

        // La empresa sale de la VACANTE, no del que llama. `authorize('view')`
        // ya decidió quién puede mirarla, y un reclutador —que no es miembro de
        // ninguna empresa— necesita poder leer el borrador que le va a proponer
        // al cliente. Resolverla desde el usuario le devolvía 404.
        $company = $vacancy->company;

        if ($company === null) {
            return $this->error(
                'La vacante no está vinculada a una empresa.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        try {
            $pdf = $this->contracts->previewPdf($company, $user, null, $vacancy);
        } catch (RuntimeException $e) {
            // La vacante no tiene honorarios propios: no hay adenda que firmar.
            return $this->error($e->getMessage(), status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                'No pudimos generar la vista previa de la adenda.',
                status: HttpStatus::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response($pdf, HttpStatus::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="adenda-humae-borrador.pdf"',
        ]);
    }

    public function store(SignVacancyAddendumRequest $request, Vacancy $vacancy): JsonResponse
    {
        $this->authorize('view', $vacancy);

        /** @var User $user */
        $user = $request->user();

        [$company, $member] = $this->resolveCompany($user);

        if ($company === null || $company->id !== $vacancy->company_id) {
            return $this->error(
                'Esta vacante no pertenece a tu empresa.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        if (! $this->canEdit($user, $member)) {
            return $this->error(
                'Solo quien tenga rol de owner o manager puede firmar una adenda de honorarios.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        // El maestro va primero. Una empresa que firmara sólo cuánto paga, sin
        // haber firmado cómo se comporta, quedaría obligada al honorario y
        // libre de la cláusula que le prohíbe contactar candidatos por fuera.
        $master = CompanyContract::masterFor($company->id);

        if ($master === null) {
            return $this->error(
                'Primero hay que firmar el contrato de prestación de servicios de la empresa.',
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        if (CompanyContract::addendumFor($vacancy->id) !== null) {
            return $this->error(
                'Esta vacante ya tiene una adenda de honorarios firmada.',
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        // Cuando firma quien ya firmó el maestro, la INE y la selfie salen de
        // ahí y el wizard no las vuelve a pedir. Si firma otro, el Form Request
        // ya las exigió y estos dos vienen con archivo.
        $source = $request->evidenceSource();

        /** @var UploadedFile $signature */
        $signature = $request->file('signature');
        $identity = $request->file('identity');
        $selfie = $request->file('selfie');

        $now = Carbon::now();

        // El aviso de privacidad no se volvió a mostrar, así que la aceptación
        // vigente es la del maestro. Estampar `now()` diría que hoy aceptó algo
        // que hoy nadie le enseñó.
        $privacyAcceptedAt = $source?->privacy_accepted_at;

        try {
            $addendum = $this->contracts->sign(
                company: $company,
                signer: $user,
                files: [
                    'signature' => $signature,
                    'identity' => $identity instanceof UploadedFile ? $identity : null,
                    'selfie' => $selfie instanceof UploadedFile ? $selfie : null,
                ],
                meta: [
                    'signer_title' => $this->resolveSignerTitle($request, $source),
                    'terms_accepted_at' => $now,
                    'privacy_accepted_at' => $privacyAcceptedAt ?? $now,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
                vacancy: $vacancy,
                evidenceSource: $source,
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), status: HttpStatus::HTTP_CONFLICT);
        }

        return $this->success(
            message: 'Adenda firmada. Esta vacante se facturará con estos honorarios.',
            data: CompanyContractResource::make($addendum),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    /**
     * El cargo del firmante: el que declare ahora, o el que ya declaró en el
     * maestro cuando el wizard no se lo volvió a preguntar.
     */
    private function resolveSignerTitle(
        SignVacancyAddendumRequest $request,
        ?CompanyContract $source,
    ): string {
        $declared = $request->input('signer_title');

        if (is_string($declared) && trim($declared) !== '') {
            return trim($declared);
        }

        if ($source !== null) {
            return $source->signer_title;
        }

        // Inalcanzable por validación —sin acreditación previa `signer_title` es
        // `required`— pero el contrato no puede quedar con el cargo vacío.
        return 'Representante legal';
    }
}
