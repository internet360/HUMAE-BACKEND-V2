<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Contracts\CompanyOwned;
use Database\Factories\CompanyContractFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Contrato de prestación de servicios firmado por una empresa cliente.
 *
 * Registro inmutable por diseño: además de los archivos, guarda la copia de los
 * términos comerciales vigentes al firmar y la evidencia de la firma electrónica.
 * Nada de esto se reescribe — una renegociación es un contrato nuevo.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $vacancy_id null = contrato maestro; con valor = adenda de esa vacante
 * @property int $signed_by_user_id
 * @property string $folio
 * @property string $signer_title
 * @property array<string, mixed> $terms
 * @property string $signature_path
 * @property string $identity_path
 * @property string $selfie_path
 * @property string $pdf_path
 * @property string $pdf_hash
 * @property string|null $timestamp_path
 * @property Carbon|null $timestamped_at
 * @property Carbon $signed_at
 * @property Carbon|null $terms_accepted_at
 * @property Carbon|null $privacy_accepted_at
 * @property string|null $signed_ip
 * @property string|null $signed_user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CompanyContract extends Model implements CompanyOwned
{
    use BelongsToCompany;

    /** @use HasFactory<CompanyContractFactory> */
    use HasFactory;

    /**
     * Anular ≠ borrar. Un contrato anulado sale de `Company::latestContract()`
     * —lo que habilita a la empresa a firmar de nuevo— pero conserva el PDF, la
     * huella y la constancia, que son la evidencia de lo que se aceptó.
     */
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'vacancy_id',
        'signed_by_user_id',
        'folio',
        'signer_title',
        'terms',
        'signature_path',
        'identity_path',
        'selfie_path',
        'pdf_path',
        'pdf_hash',
        'timestamp_path',
        'timestamped_at',
        'signed_at',
        'terms_accepted_at',
        'privacy_accepted_at',
        'signed_ip',
        'signed_user_agent',
    ];

    /**
     * Las rutas de archivo y el hash nunca viajan al cliente: el PDF se sirve por
     * el endpoint autenticado de descarga, y la firma/INE/selfie no se exponen.
     *
     * @var list<string>
     */
    protected $hidden = [
        'signature_path',
        'identity_path',
        'selfie_path',
        'pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'terms' => 'array',
            'signed_at' => 'datetime',
            'timestamped_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
        ];
    }

    /**
     * El contrato maestro vigente de una empresa, o null.
     *
     * Maestro = `vacancy_id` nulo. Es el que rige la relación completa —la
     * cláusula Primera, la que prohíbe contactar candidatos por fuera— y por eso
     * es el que consulta el gate de entrevistas. Una adenda de vacante no lo
     * sustituye: sólo cambia honorarios de esa colocación.
     */
    public static function masterFor(int $companyId): ?self
    {
        return self::acrossCompanies()
            ->where('company_id', $companyId)
            ->whereNull('vacancy_id')
            ->orderByDesc('signed_at')
            ->first();
    }

    /**
     * La adenda firmada de una vacante, o null si esa vacante se factura con el
     * contrato maestro.
     */
    public static function addendumFor(int $vacancyId): ?self
    {
        return self::acrossCompanies()
            ->where('vacancy_id', $vacancyId)
            ->orderByDesc('signed_at')
            ->first();
    }

    public function isAddendum(): bool
    {
        return $this->vacancy_id !== null;
    }

    /** @return BelongsTo<Vacancy, $this> */
    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by_user_id');
    }

    /**
     * Un contrato cuenta como sellado sólo si la constancia NOM-151 llegó. Sin
     * ella la firma sigue siendo válida, pero la integridad no está acreditada
     * por un tercero.
     */
    public function isTimestamped(): bool
    {
        return $this->timestamp_path !== null;
    }

    /**
     * Todo lo que este contrato tiene guardado en el disco privado.
     *
     * Centralizado acá para que quien limpie archivos no tenga que recordar la
     * lista: olvidarse uno deja datos personales (INE, selfie) huérfanos en el
     * servidor después de borrar la fila.
     *
     * @return list<string>
     */
    public function storedPaths(): array
    {
        return array_values(array_filter([
            $this->signature_path,
            $this->identity_path,
            $this->selfie_path,
            $this->pdf_path,
            $this->timestamp_path,
        ], static fn (?string $path): bool => is_string($path) && $path !== ''));
    }
}
