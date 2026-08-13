<?php

declare(strict_types=1);

use App\Enums\AssignmentStage;
use App\Enums\CandidateState;
use App\Enums\CompanyMemberRole;
use App\Enums\PlacementChargeState;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyContract;
use App\Models\CompanyMember;
use App\Models\PlacementCharge;
use App\Models\SalaryCurrency;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Services\CompanyContractService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/**
 * Sueldo final confirmado y cargo por colocación.
 *
 * El cargo es un registro contable: nace de un hecho —la contratación— y
 * congela su cálculo. Lo que estos casos protegen es que no se devengue de
 * menos (colocación cerrada sin cargo), de más (doble cobro), ni sin sustento
 * (sin contrato firmado o sin sueldo confirmado).
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

/**
 * @return array{assignment: VacancyAssignment, company: Company, owner: User, vacancy: Vacancy, currency: SalaryCurrency}
 */
function chargeScenario(bool $withContract = true, ?float $feePercentage = null, ?float $feeAmount = null): array
{
    $company = Company::factory()->create();

    if ($withContract) {
        // El factory trae `terms.fee_kind = percentage_annual_gross` y
        // `fee_value = 12.0`.
        CompanyContract::factory()->create(['company_id' => $company->id]);
    }

    $owner = User::factory()->create();
    $owner->assignRole(UserRole::CompanyUser->value);
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $owner->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::EntrevistasEnCurso->value,
        'fee_percentage' => $feePercentage,
        'fee_amount' => $feeAmount,
    ]);

    $profile = CandidateProfile::factory()->create(['state' => CandidateState::Activo->value]);

    $assignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $profile->id,
        'stage' => AssignmentStage::Finalist,
    ]);

    $currency = SalaryCurrency::where('code', 'MXN')->first()
        ?? SalaryCurrency::factory()->create(['code' => 'MXN']);

    return compact('assignment', 'company', 'owner', 'vacancy', 'currency');
}

function chargeRecruiter(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($user);

    return $user;
}

function confirmSalary(VacancyAssignment $assignment, SalaryCurrency $currency, float $amount = 38000, string $period = 'mes'): void
{
    test()->postJson("/api/v1/assignments/{$assignment->id}/final-salary", [
        'final_salary_amount' => $amount,
        'final_salary_period' => $period,
        'final_salary_currency_id' => $currency->id,
    ])->assertOk();
}

/*
|--------------------------------------------------------------------------
| Sueldo final
|--------------------------------------------------------------------------
*/

it('lets HUMAE capture the final salary and signs who did it', function (): void {
    $recruiter = chargeRecruiter();
    ['assignment' => $assignment, 'currency' => $currency] = chargeScenario();

    confirmSalary($assignment, $currency);

    $fresh = $assignment->fresh();

    expect((float) $fresh->final_salary_amount)->toBe(38000.0)
        ->and($fresh->final_salary_period)->toBe('mes')
        ->and($fresh->final_salary_confirmed_by_user_id)->toBe($recruiter->id)
        ->and($fresh->final_salary_confirmed_at)->not->toBeNull();
});

it('does not let the company write the base of what it will be charged', function (): void {
    ['assignment' => $assignment, 'owner' => $owner, 'currency' => $currency] = chargeScenario();
    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/assignments/{$assignment->id}/final-salary", [
        'final_salary_amount' => 1,
        'final_salary_period' => 'mes',
        'final_salary_currency_id' => $currency->id,
    ])->assertForbidden();

    expect($assignment->fresh()->final_salary_amount)->toBeNull();
});

it('refuses a period it cannot annualise without knowing the shift', function (): void {
    chargeRecruiter();
    ['assignment' => $assignment, 'currency' => $currency] = chargeScenario();

    foreach (['hora', 'dia'] as $period) {
        $this->postJson("/api/v1/assignments/{$assignment->id}/final-salary", [
            'final_salary_amount' => 250,
            'final_salary_period' => $period,
            'final_salary_currency_id' => $currency->id,
        ])->assertStatus(422)->assertJsonValidationErrors('final_salary_period');
    }
});

/*
|--------------------------------------------------------------------------
| Devengo
|--------------------------------------------------------------------------
*/

it('accrues the charge from the signed contract terms', function (): void {
    $recruiter = chargeRecruiter();
    ['assignment' => $assignment, 'company' => $company, 'currency' => $currency] = chargeScenario();

    confirmSalary($assignment, $currency);

    $this->postJson("/api/v1/assignments/{$assignment->id}/hire")->assertOk();

    $charge = PlacementCharge::acrossCompanies()->firstOrFail();

    // 38,000 × 12 = 456,000 anual; 12% = 54,720.
    expect($charge->state)->toBe(PlacementChargeState::PorFacturar)
        ->and($charge->fee_source)->toBe(PlacementCharge::SOURCE_CONTRACT)
        ->and($charge->fee_kind)->toBe('percentage_annual_gross')
        ->and((float) $charge->annual_base)->toBe(456000.0)
        ->and((float) $charge->amount)->toBe(54720.0)
        ->and($charge->company_id)->toBe($company->id)
        ->and($charge->accrued_by_user_id)->toBe($recruiter->id)
        ->and($charge->salary_confirmed_by_user_id)->toBe($recruiter->id)
        ->and($charge->company_contract_id)->not->toBeNull();
});

it('ignores an unsigned fee typed into the vacancy', function (): void {
    chargeRecruiter();
    ['assignment' => $assignment, 'currency' => $currency] = chargeScenario(feePercentage: 20.0);

    confirmSalary($assignment, $currency);
    $this->postJson("/api/v1/assignments/{$assignment->id}/hire")->assertOk();

    $charge = PlacementCharge::acrossCompanies()->firstOrFail();

    // El 20% está escrito en la vacante, pero nadie lo firmó y la empresa ni lo
    // vio. Facturar con él daría el peor escenario: el cliente abre su contrato,
    // lee 12%, y tiene razón. Manda el contrato.
    expect($charge->fee_source)->toBe(PlacementCharge::SOURCE_CONTRACT)
        ->and((float) $charge->fee_value)->toBe(12.0)
        ->and((float) $charge->amount)->toBe(54720.0);
});

it('bills with the signed addendum when the vacancy has one', function (): void {
    chargeRecruiter();
    ['assignment' => $assignment, 'company' => $company, 'vacancy' => $vacancy, 'currency' => $currency]
        = chargeScenario(feePercentage: 20.0);

    // La adenda es un contrato firmado más, con `vacancy_id`. Ahora el 20% sí
    // tiene instrumento detrás.
    $terms = app(CompanyContractService::class)->addendumTerms($vacancy);
    CompanyContract::factory()->create([
        'company_id' => $company->id,
        'vacancy_id' => $vacancy->id,
        'terms' => $terms,
    ]);

    confirmSalary($assignment, $currency);
    $this->postJson("/api/v1/assignments/{$assignment->id}/hire")->assertOk();

    $charge = PlacementCharge::acrossCompanies()->firstOrFail();

    expect($charge->fee_source)->toBe(PlacementCharge::SOURCE_VACANCY_ADDENDUM)
        ->and((float) $charge->fee_value)->toBe(20.0)
        ->and((float) $charge->amount)->toBe(91200.0)
        ->and($charge->company_contract_id)->not->toBeNull();
});

it('supports a fixed fee coming from a signed addendum', function (): void {
    chargeRecruiter();
    ['assignment' => $assignment, 'company' => $company, 'vacancy' => $vacancy, 'currency' => $currency]
        = chargeScenario(feeAmount: 50000.0);

    $terms = app(CompanyContractService::class)->addendumTerms($vacancy);
    CompanyContract::factory()->create([
        'company_id' => $company->id,
        'vacancy_id' => $vacancy->id,
        'terms' => $terms,
    ]);

    confirmSalary($assignment, $currency);
    $this->postJson("/api/v1/assignments/{$assignment->id}/hire")->assertOk();

    $charge = PlacementCharge::acrossCompanies()->firstOrFail();

    expect($charge->fee_kind)->toBe('fixed_amount')
        ->and((float) $charge->amount)->toBe(50000.0);
});

it('does not let an addendum stand in for the master contract at the interview gate', function (): void {
    chargeRecruiter();
    ['company' => $company, 'vacancy' => $vacancy] = chargeScenario(withContract: false, feePercentage: 20.0);

    // Firmó cuánto paga, no cómo se comporta: la cláusula Primera vive en el
    // maestro y sigue sin firmar.
    CompanyContract::factory()->create([
        'company_id' => $company->id,
        'vacancy_id' => $vacancy->id,
    ]);

    expect(CompanyContract::masterFor($company->id))->toBeNull()
        ->and(CompanyContract::addendumFor($vacancy->id))->not->toBeNull();
});

it('annualises a yearly salary without multiplying it again', function (): void {
    chargeRecruiter();
    ['assignment' => $assignment, 'currency' => $currency] = chargeScenario();

    confirmSalary($assignment, $currency, amount: 456000, period: 'anio');
    $this->postJson("/api/v1/assignments/{$assignment->id}/hire")->assertOk();

    $charge = PlacementCharge::acrossCompanies()->firstOrFail();

    expect((float) $charge->annual_base)->toBe(456000.0)
        ->and((float) $charge->amount)->toBe(54720.0);
});

/*
|--------------------------------------------------------------------------
| Los candados
|--------------------------------------------------------------------------
*/

it('refuses to hire without a confirmed final salary', function (): void {
    chargeRecruiter();
    ['assignment' => $assignment] = chargeScenario();

    $this->postJson("/api/v1/assignments/{$assignment->id}/hire")->assertStatus(409);

    // Nada a medias: ni cargo, ni etapa movida, ni vacante cerrada.
    expect(PlacementCharge::acrossCompanies()->count())->toBe(0)
        ->and($assignment->fresh()->stage)->toBe(AssignmentStage::Finalist)
        ->and(Vacancy::acrossCompanies()->find($assignment->vacancy_id)->state)
        ->toBe(VacancyState::EntrevistasEnCurso);
});

it('refuses to accrue without a signed contract — the second lock', function (): void {
    chargeRecruiter();
    ['assignment' => $assignment, 'currency' => $currency] = chargeScenario(withContract: false);

    confirmSalary($assignment, $currency);

    // Se llegó a `finalist` sin agendar entrevista, así que el gate del
    // agendado nunca corrió. Este es el candado que cierra ese camino.
    $this->postJson("/api/v1/assignments/{$assignment->id}/hire")
        ->assertStatus(409)
        ->assertJsonPath('errors.contract.0', 'unsigned');

    expect(PlacementCharge::acrossCompanies()->count())->toBe(0)
        ->and($assignment->fresh()->stage)->toBe(AssignmentStage::Finalist);
});

it('never charges twice for the same placement', function (): void {
    chargeRecruiter();
    ['assignment' => $assignment, 'currency' => $currency] = chargeScenario();

    confirmSalary($assignment, $currency);
    $this->postJson("/api/v1/assignments/{$assignment->id}/hire")->assertOk();
    $this->postJson("/api/v1/assignments/{$assignment->id}/hire")->assertStatus(409);

    expect(PlacementCharge::acrossCompanies()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Quién cierra la colocación
|--------------------------------------------------------------------------
*/

it('lets the employer close the placement from its own dashboard', function (): void {
    $recruiter = chargeRecruiter();
    ['assignment' => $assignment, 'owner' => $owner, 'currency' => $currency] = chargeScenario();

    confirmSalary($assignment, $currency);

    Sanctum::actingAs($owner);
    $this->postJson("/api/v1/assignments/{$assignment->id}/hire")->assertOk();

    $charge = PlacementCharge::acrossCompanies()->firstOrFail();

    expect($assignment->fresh()->stage)->toBe(AssignmentStage::Hired)
        // Quién cerró y quién confirmó el sueldo son personas distintas, y el
        // cargo guarda las dos.
        ->and($charge->accrued_by_user_id)->toBe($owner->id)
        ->and($charge->salary_confirmed_by_user_id)->toBe($recruiter->id);
});

it('does not let a company close a candidate it was never shown', function (): void {
    chargeRecruiter();
    ['assignment' => $assignment, 'owner' => $owner, 'currency' => $currency] = chargeScenario();

    confirmSalary($assignment, $currency);
    $assignment->forceFill(['stage' => AssignmentStage::Sourced->value])->save();

    Sanctum::actingAs($owner);
    $this->postJson("/api/v1/assignments/{$assignment->id}/hire")->assertForbidden();

    expect(PlacementCharge::acrossCompanies()->count())->toBe(0);
});

it('locks a candidate out of hiring anybody', function (): void {
    ['assignment' => $assignment] = chargeScenario();

    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/assignments/{$assignment->id}/hire")->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Cartera
|--------------------------------------------------------------------------
*/

it('lists accrued charges with their total for HUMAE only', function (): void {
    chargeRecruiter();
    ['assignment' => $assignment, 'currency' => $currency] = chargeScenario();

    confirmSalary($assignment, $currency);
    $this->postJson("/api/v1/assignments/{$assignment->id}/hire")->assertOk();

    $this->getJson('/api/v1/placement-charges')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        // Comparación numérica: JSON serializa 54720.0 como 54720 y
        // `assertJsonPath` compara con identidad estricta.
        ->assertJsonPath('data.0.amount', fn ($v) => (float) $v === 54720.0)
        ->assertJsonPath('data.0.fee_source', PlacementCharge::SOURCE_CONTRACT)
        ->assertJsonPath('meta.accrued_total', fn ($v) => (float) $v === 54720.0);
});

it('keeps the charge portfolio away from the client company', function (): void {
    ['owner' => $owner] = chargeScenario();
    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/placement-charges')->assertForbidden();
});
