<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CincelTimestampException;
use App\Helpers\LocalFileStorage;
use App\Models\Company;
use App\Models\CompanyContract;
use App\Models\ContractSetting;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Throwable;

/**
 * Emisión del contrato de prestación de servicios de una empresa cliente.
 *
 * Secuencia (misma que RED1A1, `user.controller.ts#cincel`):
 *
 *   1. Resguardar firma trazada, identificación y selfie en el disco privado.
 *   2. Renderizar el Blade del contrato a PDF con los términos vigentes copiados.
 *   3. Hashear el PDF y pedir la constancia NOM-151 a CINCEL.
 *   4. Persistir el registro con la evidencia de la firma.
 *
 * El paso 3 es el único que puede fallar sin abortar: si CINCEL no responde, la
 * firma ya ocurrió y el contrato queda emitido sin sello, reintentable.
 */
class CompanyContractService
{
    public function __construct(
        private readonly LocalFileStorage $storage,
        private readonly CincelTimestampService $cincel,
    ) {}

    /**
     * @param  array{signature: UploadedFile, identity: UploadedFile, selfie: UploadedFile}  $files
     * @param  array{signer_title: string, terms_accepted_at?: Carbon|null, privacy_accepted_at?: Carbon|null, ip?: string|null, user_agent?: string|null}  $meta
     */
    public function sign(Company $company, User $signer, array $files, array $meta): CompanyContract
    {
        $terms = $this->currentTerms();
        $signedAt = Carbon::now();

        $folder = 'contracts/'.$company->id;

        // Los archivos entran al disco privado antes de abrir la transacción:
        // escribir en disco no es transaccional y no queremos sostener un lock
        // de tabla mientras se suben.
        $signaturePath = $this->store($files['signature'], $folder.'/signature');
        $identityPath = $this->store($files['identity'], $folder.'/identity');
        $selfiePath = $this->store($files['selfie'], $folder.'/selfie');

        try {
            $folio = $this->allocateFolio($signedAt);

            $contract = new CompanyContract([
                'company_id' => $company->id,
                'signed_by_user_id' => $signer->id,
                'folio' => $folio,
                'signer_title' => $meta['signer_title'],
                'terms' => $terms,
                'signature_path' => $signaturePath,
                'identity_path' => $identityPath,
                'selfie_path' => $selfiePath,
                'signed_at' => $signedAt,
                'terms_accepted_at' => $meta['terms_accepted_at'] ?? null,
                'privacy_accepted_at' => $meta['privacy_accepted_at'] ?? null,
                'signed_ip' => $meta['ip'] ?? null,
                'signed_user_agent' => $meta['user_agent'] ?? null,
            ]);

            $pdf = $this->renderPdf($contract, $company, $signer, $terms, $signaturePath);

            $pdfPath = $folder.'/'.$folio.'.pdf';
            Storage::disk('local')->put($pdfPath, $pdf);

            // Hash sobre el base64 del PDF, no sobre los bytes crudos: es el
            // criterio de RED1A1 y hay que conservarlo para que una constancia
            // emitida por cualquiera de los dos sistemas se reverifique igual.
            $hash = hash('sha256', base64_encode($pdf));

            $contract->pdf_path = $pdfPath;
            $contract->pdf_hash = $hash;

            [$timestampPath, $timestampedAt] = $this->timestamp($hash, $folder);
            $contract->timestamp_path = $timestampPath;
            $contract->timestamped_at = $timestampedAt;

            DB::transaction(static function () use ($contract): void {
                $contract->save();
            });

            return $contract;
        } catch (Throwable $e) {
            // Sin registro no hay quién limpie estos archivos después.
            foreach ([$signaturePath, $identityPath, $selfiePath] as $orphan) {
                $this->storage->destroy($orphan, 'local');
            }

            throw $e;
        }
    }

    /**
     * Reintenta el sello de un contrato ya firmado que quedó sin constancia.
     */
    public function retryTimestamp(CompanyContract $contract): bool
    {
        if ($contract->isTimestamped()) {
            return true;
        }

        [$path, $at] = $this->timestamp(
            $contract->pdf_hash,
            'contracts/'.$contract->company_id,
        );

        if ($path === null) {
            return false;
        }

        $contract->forceFill([
            'timestamp_path' => $path,
            'timestamped_at' => $at,
        ])->save();

        return true;
    }

    /**
     * Borrador del contrato con los términos vigentes, sin firma y sin persistir.
     *
     * Existe para que nadie acepte un contrato que no pudo leer: el wizard de
     * firma lo muestra tal cual, así que lo que se firma y lo que se leyó salen
     * del mismo Blade y no pueden desincronizarse.
     */
    public function previewPdf(Company $company, User $signer, ?string $signerTitle = null): string
    {
        $terms = $this->currentTerms();

        $draft = new CompanyContract([
            'company_id' => $company->id,
            'signed_by_user_id' => $signer->id,
            'folio' => 'BORRADOR',
            'signer_title' => $signerTitle ?? 'Representante legal',
            'terms' => $terms,
            'signed_at' => Carbon::now(),
        ]);

        return $this->renderPdf($draft, $company, $signer, $terms, null);
    }

    /**
     * Copia de los términos vigentes, tal como quedarán congelados en el contrato.
     *
     * La fuente de verdad es `contract_settings` (editable desde el panel de
     * admin), sembrada la primera vez desde `config/contracts.php` para que los
     * deploys existentes no cambien de comportamiento.
     *
     * Se valida acá y no en el Blade porque un contrato sin honorarios o sin
     * apoderado que lo firme no debe existir.
     *
     * @return array<string, mixed>
     */
    public function currentTerms(): array
    {
        $settings = ContractSetting::current();
        $missing = $settings->missingToIssue();

        if ($missing !== []) {
            throw new RuntimeException(
                'Faltan condiciones del contrato ('.implode(', ', $missing).
                '). Complétalas en Administración → Contrato.'
            );
        }

        if (! in_array($settings->fee_kind, ContractSetting::FEE_KINDS, true)) {
            throw new RuntimeException("Forma de honorarios inválida: «{$settings->fee_kind}».");
        }

        if ($settings->payment_days <= 0 || $settings->warranty_days <= 0) {
            throw new RuntimeException('El plazo de pago y la garantía deben ser mayores a cero.');
        }

        if (trim($settings->provider_name) === '') {
            throw new RuntimeException('El nombre del prestador no puede estar vacío.');
        }

        return [
            // Versión de los términos: permite responder "¿qué condiciones
            // aceptó esta empresa?" sin comparar campo por campo.
            'version' => $settings->version,
            'provider_name' => $settings->provider_name,
            'fee_kind' => $settings->fee_kind,
            'fee_value' => $settings->fee_value,
            'fee_amount_words' => $settings->fee_amount_words,
            'payment_days' => $settings->payment_days,
            'payment_day_kind' => $settings->payment_day_kind,
            'warranty_days' => $settings->warranty_days,
            'city' => $settings->city,
            'jurisdiction' => $settings->jurisdiction,
            'signatory' => [
                'name' => $settings->signatory_name,
                'title' => $settings->signatory_title,
            ],
            // La ruta de la firma viaja en el snapshot para que reimprimir un
            // contrato viejo use la firma que tenía, no la que esté cargada hoy.
            'signature_path' => $settings->signature_path,
        ];
    }

    /**
     * Regenera el PDF de un contrato ya emitido.
     *
     * OJO: el resultado NO sirve para reverificar la constancia. DomPDF escribe
     * CreationDate en los metadatos, así que un PDF regenerado tiene otro hash
     * que el sellado. Para verificar hay que leer el archivo almacenado.
     */
    public function renderStored(CompanyContract $contract): string
    {
        $contract->loadMissing(['company', 'signedBy']);

        /** @var Company $company */
        $company = $contract->company;
        /** @var User $signer */
        $signer = $contract->signedBy;

        return $this->renderPdf($contract, $company, $signer, $contract->terms, $contract->signature_path);
    }

    /**
     * @param  array<string, mixed>  $terms
     * @param  string|null  $signaturePath  nulo sólo en el borrador de vista previa
     */
    private function renderPdf(
        CompanyContract $contract,
        Company $company,
        User $signer,
        array $terms,
        ?string $signaturePath,
    ): string {
        $signatory = is_array($terms['signatory'] ?? null) ? $terms['signatory'] : [];

        $html = View::make('pdf.company-contract', [
            'contract' => $contract,
            'company' => $company,
            'signer' => $signer,
            'signerTitle' => $contract->signer_title,
            'terms' => $terms,
            'signatureSrc' => $signaturePath === null
                ? null
                : $this->diskImageDataUri($signaturePath),
            'humaeSignature' => [
                // Del snapshot: reimprimir un contrato viejo debe usar la firma
                // que tenía cuando se firmó, no la que esté cargada hoy.
                'src' => $this->signatoryImageDataUri(
                    is_string($terms['signature_path'] ?? null) ? $terms['signature_path'] : null,
                ),
                'name' => $signatory['name'] ?? null,
                'title' => $signatory['title'] ?? null,
            ],
            'logoSrc' => $this->resourceImageDataUri('views/pdf/humae-logo.png'),
            'evidence' => [
                'ip' => $contract->signed_ip,
                'user_agent' => $contract->signed_user_agent,
                'accepted_at' => $contract->terms_accepted_at?->format('Y-m-d H:i:s'),
            ],
        ])->render();

        $options = new Options;
        $options->setChroot([resource_path(), base_path('public')]);
        // Sin recursos remotos: el PDF tiene que ser autocontenido y determinista
        // para que su hash valga como huella del documento.
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @return array{0: string|null, 1: Carbon|null}
     */
    private function timestamp(string $hash, string $folder): array
    {
        try {
            $asn1 = $this->cincel->fetch($hash);
        } catch (CincelTimestampException $e) {
            // La firma ya ocurrió: no la tiramos porque el tercero esté caído.
            report($e);

            return [null, null];
        }

        $path = $folder.'/'.$hash.'.asn1';
        Storage::disk('local')->put($path, $asn1);

        return [$path, Carbon::now()];
    }

    private function store(UploadedFile $file, string $folder): string
    {
        return $this->storage->upload($file, $folder, ['disk' => 'local'])['public_id'];
    }

    /**
     * Folio consecutivo por año. Se cuenta sobre todas las empresas
     * (`acrossCompanies`) porque el consecutivo es de HUMAE, no del tenant — y
     * sin eso el scope de tenancy devolvería un conteo parcial y colisionaría
     * contra el índice único.
     */
    private function allocateFolio(Carbon $signedAt): string
    {
        $year = $signedAt->format('Y');
        $prefix = "HUMAE-CTR-{$year}-";

        $last = CompanyContract::acrossCompanies()
            ->where('folio', 'like', $prefix.'%')
            ->orderByDesc('folio')
            ->value('folio');

        $next = 1;
        if (is_string($last)) {
            $next = ((int) substr($last, strlen($prefix))) + 1;
        }

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function diskImageDataUri(string $path): string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            throw new RuntimeException("No se encontró la firma en «{$path}».");
        }

        $contents = (string) $disk->get($path);
        $mime = $disk->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    /**
     * Firma del apoderado como data URI.
     *
     * Primero el archivo que el admin cargó desde el panel (disco privado). Si no
     * hay, se cae al PNG que vivía en `resources/` — así los despliegues que ya
     * tenían la firma ahí siguen imprimiéndola sin recargarla.
     *
     * Devuelve `null` si no hay ninguno: el contrato se genera igual, con la
     * línea del prestador vacía.
     */
    private function signatoryImageDataUri(?string $storagePath): ?string
    {
        if ($storagePath !== null && $storagePath !== '') {
            $disk = Storage::disk('local');

            if ($disk->exists($storagePath)) {
                $contents = (string) $disk->get($storagePath);

                if ($contents !== '') {
                    $mime = $disk->mimeType($storagePath) ?: 'image/png';

                    return 'data:'.$mime.';base64,'.base64_encode($contents);
                }
            }
        }

        $fallback = config('contracts.signatory.signature_path');

        return $this->resourceImageDataUri(is_string($fallback) ? $fallback : null);
    }

    private function resourceImageDataUri(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        $full = resource_path($relativePath);

        if (! is_file($full)) {
            return null;
        }

        $contents = file_get_contents($full);
        if ($contents === false || $contents === '') {
            return null;
        }

        $mime = match (strtolower(pathinfo($full, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }
}
