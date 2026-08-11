<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Términos comerciales vigentes del contrato. Tabla de un solo registro.
 *
 * @property int $id
 * @property string $provider_name
 * @property string|null $signatory_name
 * @property string|null $signatory_title
 * @property string|null $signature_path
 * @property string $fee_kind
 * @property float $fee_value
 * @property string|null $fee_amount_words
 * @property int $payment_days
 * @property string $payment_day_kind
 * @property int $warranty_days
 * @property string|null $city
 * @property string $jurisdiction
 * @property int $version
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContractSetting extends Model
{
    /** Formas de cobro admitidas en la cláusula Tercera. */
    public const FEE_KINDS = [
        'percentage_annual_gross',
        'monthly_salary_multiple',
        'fixed_amount',
    ];

    /** Cómo se cuentan los días del plazo de pago (cláusula Cuarta). */
    public const PAYMENT_DAY_KINDS = ['habiles', 'naturales'];

    /** La fila única. */
    private const SINGLETON_ID = 1;

    protected $fillable = [
        'provider_name',
        'signatory_name',
        'signatory_title',
        'signature_path',
        'fee_kind',
        'fee_value',
        'fee_amount_words',
        'payment_days',
        'payment_day_kind',
        'warranty_days',
        'city',
        'jurisdiction',
        'version',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'fee_value' => 'float',
            'payment_days' => 'integer',
            'warranty_days' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * La configuración vigente, creándola desde `config/contracts.php` la primera
     * vez.
     *
     * Sembrar desde el config y no exigir un seeder mantiene los deploys
     * funcionando: hasta que un admin entre al panel, siguen valiendo los valores
     * del `.env` que ya estaban configurados.
     */
    public static function current(): self
    {
        /** @var array<string, mixed> $defaults */
        $defaults = config('contracts');
        $signatory = is_array($defaults['signatory'] ?? null) ? $defaults['signatory'] : [];

        /** @var self $setting */
        $setting = self::query()->firstOrCreate(
            ['id' => self::SINGLETON_ID],
            [
                'provider_name' => $defaults['provider_name'] ?? 'Humae Consultoría de RH',
                'signatory_name' => $signatory['name'] ?? null,
                'signatory_title' => $signatory['title'] ?? null,
                'fee_kind' => $defaults['fee_kind'] ?? 'percentage_annual_gross',
                'fee_value' => is_numeric($defaults['fee_value'] ?? null) ? (float) $defaults['fee_value'] : 0.0,
                'fee_amount_words' => $defaults['fee_amount_words'] ?? null,
                'payment_days' => is_numeric($defaults['payment_days'] ?? null) ? (int) $defaults['payment_days'] : 5,
                'payment_day_kind' => $defaults['payment_day_kind'] ?? 'habiles',
                'warranty_days' => is_numeric($defaults['warranty_days'] ?? null) ? (int) $defaults['warranty_days'] : 90,
                'city' => $defaults['city'] ?? null,
                'jurisdiction' => $defaults['jurisdiction'] ?? '',
                'version' => 1,
            ],
        );

        return $setting;
    }

    /**
     * Lo que falta para poder emitir un contrato completo.
     *
     * Se expone al panel para que el admin vea qué le falta antes de que una
     * empresa se tope con el error al firmar.
     *
     * @return list<string>
     */
    public function missingToIssue(): array
    {
        $missing = [];

        if (! is_string($this->signatory_name) || trim($this->signatory_name) === '') {
            $missing[] = 'signatory_name';
        }

        if (! is_string($this->signatory_title) || trim($this->signatory_title) === '') {
            $missing[] = 'signatory_title';
        }

        if ($this->fee_value <= 0) {
            $missing[] = 'fee_value';
        }

        if ($this->fee_kind === 'fixed_amount'
            && (! is_string($this->fee_amount_words) || trim($this->fee_amount_words) === '')) {
            $missing[] = 'fee_amount_words';
        }

        if (trim($this->jurisdiction) === '') {
            $missing[] = 'jurisdiction';
        }

        return $missing;
    }

    /**
     * `true` cuando se puede emitir un contrato con estas condiciones.
     *
     * La firma escaneada NO cuenta acá: sin ella el contrato sale válido aunque
     * firmado por una sola parte, y bloquear la operación por eso sería peor.
     */
    public function isReadyToIssue(): bool
    {
        return $this->missingToIssue() === [];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
