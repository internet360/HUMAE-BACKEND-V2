<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlacementChargeState;
use App\Models\Company;
use App\Models\PlacementCharge;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlacementCharge>
 */
class PlacementChargeFactory extends Factory
{
    protected $model = PlacementCharge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $monthly = 38000.00;
        $annual = $monthly * 12;

        return [
            'company_id' => Company::factory(),
            'vacancy_id' => Vacancy::factory(),
            'vacancy_assignment_id' => VacancyAssignment::factory(),
            'company_contract_id' => null,
            'state' => PlacementChargeState::PorFacturar->value,
            'fee_source' => PlacementCharge::SOURCE_CONTRACT,
            'fee_kind' => 'percentage_annual_gross',
            'fee_value' => 12.00,
            'final_salary_amount' => $monthly,
            'final_salary_period' => 'mes',
            'annual_base' => $annual,
            'amount' => $annual * 0.12,
            'accrued_at' => now(),
        ];
    }
}
