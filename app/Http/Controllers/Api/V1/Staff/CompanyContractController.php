<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Contracts\ContractLedgerEntryResource;
use App\Http\Resources\V1\Contracts\StaffContractResource;
use App\Models\Company;
use App\Models\CompanyContract;
use App\Models\User;
use App\Services\ContractLedgerService;
use App\Support\Contracts\ContractLedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Historial de contratos de una empresa cliente, visto por HUMAE.
 *
 * Vive en `Staff\` y no en `Recruiter\` ni en `Admin\` porque lo usan los dos
 * roles con exactamente el mismo alcance: la `CompanyPolicy` admite recruiter y
 * deja pasar a admin por `before()`. Duplicarlo por rol habría creado dos
 * caminos hacia la misma evidencia, y el día que uno se endurezca el otro queda
 * abierto.
 *
 * Contesta tres preguntas que hasta ahora sólo se respondían entrando a la base:
 *
 *   1. ¿Qué firmó esta empresa? — el maestro de acceso a la plataforma y cada
 *      adenda de honorarios de vacante, con su evidencia completa.
 *   2. ¿Qué le pedimos firmar y sigue pendiente? — la ausencia de maestro y cada
 *      vacante con honorario propio sin adenda.
 *   3. ¿Quién firmó y cómo lo acreditó? — INE y selfie de quien firmó.
 *
 * La tercera es la delicada: son datos personales sensibles. Por eso salen sólo
 * por `files()`, que revalida la policy en cada archivo y deja una entrada de
 * bitácora por cada vista. Nunca viajan embebidos en el JSON del historial.
 */
class CompanyContractController extends Controller
{
    /**
     * Los archivos que un contrato puede servir, y cómo se entregan.
     *
     * `inline` gobierna la disposición: la identificación y la selfie se ven en
     * pantalla; el PDF y la constancia se bajan.
     *
     * @var array<string, array{column: string, inline: bool, filename: string, sensitive: bool}>
     */
    private const FILES = [
        'pdf' => ['column' => 'pdf_path', 'inline' => false, 'filename' => 'contrato', 'sensitive' => false],
        'identity' => ['column' => 'identity_path', 'inline' => true, 'filename' => 'identificacion', 'sensitive' => true],
        'selfie' => ['column' => 'selfie_path', 'inline' => true, 'filename' => 'selfie', 'sensitive' => true],
        'signature' => ['column' => 'signature_path', 'inline' => true, 'filename' => 'firma', 'sensitive' => false],
        'timestamp' => ['column' => 'timestamp_path', 'inline' => false, 'filename' => 'constancia-nom151', 'sensitive' => false],
    ];

    public function __construct(
        private readonly ContractLedgerService $ledger,
    ) {}

    /**
     * Historial completo de una empresa: firmados, anulados y pendientes.
     */
    public function index(Company $company): JsonResponse
    {
        $this->authorize('viewContracts', $company);

        $entries = $this->ledger->forCompany($company);

        return $this->success(
            message: 'Historial de contratos.',
            data: $entries->map(fn (ContractLedgerEntry $entry) => new ContractLedgerEntryResource(
                $entry,
                $this->ledger->pendingTerms($entry),
            ))->all(),
            meta: [
                'company' => [
                    'id' => $company->id,
                    'legal_name' => $company->legal_name,
                    'trade_name' => $company->trade_name,
                ],
                'summary' => $this->ledger->summarize($entries),
            ],
        );
    }

    /**
     * Un contrato concreto con toda su evidencia.
     */
    public function show(CompanyContract $contract): JsonResponse
    {
        $this->authorizeContract($contract);

        $contract->loadMissing(['company', 'vacancy', 'signedBy']);

        return $this->success(
            message: 'Contrato.',
            data: StaffContractResource::make($contract),
        );
    }

    /**
     * Sirve uno de los archivos del contrato.
     *
     * Un solo endpoint para los cinco en lugar de cinco endpoints: la
     * autorización, la bitácora y el chequeo de existencia son idénticos, y
     * repetirlos cinco veces es repetir cinco veces la oportunidad de olvidarse
     * uno.
     */
    public function files(Request $request, CompanyContract $contract, string $kind): StreamedResponse|JsonResponse
    {
        $spec = self::FILES[$kind] ?? null;

        if ($spec === null) {
            return $this->error('Archivo desconocido.', status: HttpStatus::HTTP_NOT_FOUND);
        }

        $this->authorizeContract($contract, evidence: $spec['sensitive']);

        /** @var string|null $path */
        $path = $contract->{$spec['column']};

        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            return $this->error(
                'Este contrato no tiene ese archivo disponible.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        if ($spec['sensitive']) {
            $this->auditEvidenceView($request, $contract, $kind);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'bin';
        $filename = $spec['filename'].'-'.$contract->folio.'.'.$extension;

        return Storage::disk('local')->response(
            $path,
            $filename,
            [
                'Content-Disposition' => ($spec['inline'] ? 'inline' : 'attachment')
                    .'; filename="'.$filename.'"',
                // Documentos de identidad no se quedan en el caché del disco de
                // nadie: ni del navegador ni de un proxy intermedio.
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    /**
     * Ver una INE o una selfie deja rastro.
     *
     * No es burocracia: es la contracara de haber abierto estos archivos al
     * equipo. Si mañana hay que responder quién consultó la identificación de un
     * firmante, la respuesta tiene que existir — y tiene que escribirse acá, en
     * el único camino por el que salen.
     */
    private function auditEvidenceView(Request $request, CompanyContract $contract, string $kind): void
    {
        /** @var User|null $user */
        $user = $request->user();

        activity('contract-evidence')
            ->performedOn($contract)
            ->causedBy($user)
            ->withProperties([
                'kind' => $kind,
                'folio' => $contract->folio,
                'company_id' => $contract->company_id,
                'ip' => $request->ip(),
            ])
            ->log('Consultó la evidencia de identidad de un contrato.');
    }

    /**
     * La autorización de un contrato es la de su empresa: quien puede ver los
     * contratos de una empresa puede ver los suyos, y nadie más.
     */
    private function authorizeContract(CompanyContract $contract, bool $evidence = false): void
    {
        /** @var Company|null $company */
        $company = Company::acrossCompanies()->find($contract->company_id);

        if ($company === null) {
            abort(HttpStatus::HTTP_NOT_FOUND, 'La empresa del contrato ya no existe.');
        }

        $this->authorize($evidence ? 'viewContractEvidence' : 'viewContracts', $company);
    }
}
