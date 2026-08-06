<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CincelTimestampException;
use App\Helpers\LocalFileStorage;
use App\Models\Company;
use App\Models\CompanyContract;
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
     * Copia de los términos vigentes. Se valida aquí y no en el Blade porque un
     * contrato sin honorarios o sin apoderado que lo firme no debe existir.
     *
     * @return array<string, mixed>
     */
    public function currentTerms(): array
    {
        /** @var array<string, mixed> $config */
        $config = config('contracts');

        $feeKind = $config['fee_kind'] ?? null;
        $feeValue = $config['fee_value'] ?? null;

        if (! in_array($feeKind, ['percentage_annual_gross', 'monthly_salary_multiple', 'fixed_amount'], true)) {
            throw new RuntimeException(
                'config/contracts.php: fee_kind inválido («'.(is_scalar($feeKind) ? (string) $feeKind : 'null').'»).'
            );
        }

        if (! is_numeric($feeValue) || (float) $feeValue <= 0) {
            throw new RuntimeException('config/contracts.php: fee_value debe ser un número mayor a cero.');
        }

        if ($feeKind === 'fixed_amount' && ! is_string($config['fee_amount_words'] ?? null)) {
            throw new RuntimeException(
                'config/contracts.php: fee_kind «fixed_amount» exige fee_amount_words (el monto en letra).'
            );
        }

        foreach (['warranty_days', 'payment_days'] as $key) {
            if (! is_numeric($config[$key] ?? null) || (int) $config[$key] <= 0) {
                throw new RuntimeException("config/contracts.php: {$key} debe ser un entero mayor a cero.");
            }
        }

        foreach (['provider_name', 'jurisdiction'] as $key) {
            if (! is_string($config[$key] ?? null) || trim((string) $config[$key]) === '') {
                throw new RuntimeException("config/contracts.php: {$key} no puede estar vacío.");
            }
        }

        $signatory = is_array($config['signatory'] ?? null) ? $config['signatory'] : [];
        foreach (['name', 'title'] as $key) {
            if (! is_string($signatory[$key] ?? null) || trim((string) $signatory[$key]) === '') {
                throw new RuntimeException(
                    "config/contracts.php: signatory.{$key} es obligatorio — sin apoderado el contrato saldría firmado por una sola parte."
                );
            }
        }

        return [
            'version' => $config['version'] ?? null,
            'provider_name' => $config['provider_name'],
            'fee_kind' => $feeKind,
            'fee_value' => (float) $feeValue,
            'fee_amount_words' => $config['fee_amount_words'] ?? null,
            'payment_days' => (int) $config['payment_days'],
            'payment_day_kind' => $config['payment_day_kind'] ?? 'habiles',
            'warranty_days' => (int) $config['warranty_days'],
            'city' => $config['city'] ?? null,
            'jurisdiction' => $config['jurisdiction'],
            'signatory' => [
                'name' => $signatory['name'],
                'title' => $signatory['title'],
            ],
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
                'src' => $this->resourceImageDataUri(
                    is_string(config('contracts.signatory.signature_path'))
                        ? (string) config('contracts.signatory.signature_path')
                        : null,
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
