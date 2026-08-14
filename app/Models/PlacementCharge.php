<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlacementChargeState;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Contracts\CompanyOwned;
use Database\Factories\PlacementChargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $vacancy_id
 * @property int $vacancy_assignment_id
 * @property int|null $company_contract_id
 * @property PlacementChargeState|null $state
 * @property string $fee_source
 * @property string $fee_kind
 * @property string $fee_value
 * @property string $final_salary_amount
 * @property string $final_salary_period
 * @property string $annual_base
 * @property string $amount
 * @property int|null $salary_currency_id
 * @property int|null $salary_confirmed_by_user_id
 * @property int|null $accrued_by_user_id
 * @property Carbon|null $accrued_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlacementCharge extends Model implements CompanyOwned
{
    use BelongsToCompany;

    /** @use HasFactory<PlacementChargeFactory> */
    use HasFactory;

    use SoftDeletes;

    /** Honorarios del contrato maestro de la empresa. */
    public const SOURCE_CONTRACT = 'contract';

    /**
     * Honorarios de una adenda firmada para esta vacante.
     *
     * Las dos fuentes son contratos: nunca se factura con un número que la
     * empresa no firmó. La distinción existe para que una auditoría sepa qué
     * documento pedir cuando el monto no es el del contrato maestro.
     */
    public const SOURCE_VACANCY_ADDENDUM = 'vacancy_addendum';

    protected $fillable = [
        'company_id',
        'vacancy_id',
        'vacancy_assignment_id',
        'company_contract_id',
        'state',
        'fee_source',
        'fee_kind',
        'fee_value',
        'final_salary_amount',
        'final_salary_period',
        'annual_base',
        'amount',
        'salary_currency_id',
        'salary_confirmed_by_user_id',
        'accrued_by_user_id',
        'accrued_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => PlacementChargeState::class,
            'fee_value' => 'decimal:2',
            'final_salary_amount' => 'decimal:2',
            'annual_base' => 'decimal:2',
            'amount' => 'decimal:2',
            'accrued_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Vacancy, $this> */
    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    /** @return BelongsTo<VacancyAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(VacancyAssignment::class, 'vacancy_assignment_id');
    }

    /** @return BelongsTo<CompanyContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(CompanyContract::class, 'company_contract_id');
    }

    /** @return BelongsTo<SalaryCurrency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(SalaryCurrency::class, 'salary_currency_id');
    }
}
