<?php

declare(strict_types=1);

use App\Enums\CandidateState;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyContract;
use App\Models\CompanyMember;
use App\Models\Interview;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Gate del contrato: sin contrato firmado no se programa entrevista.
 *
 * El requisito pedía que el contrato se «generara o activara» justo al agendar
 * la primera entrevista. Eso no se puede: firmar exige el trazo, la INE y una
 * selfie que sube una persona, y quien agenda es HUMAE mientras que quien firma
 * es el empleador. Son dos actores, así que el contrato no puede ser un efecto
 * secundario del agendado — sólo su condición.
 *
 * Lo que estos casos protegen es el eslabón legal: el cargo por colocación se
 * devenga al final de este proceso, y facturarlo sin instrumento firmado deja a
 * HUMAE cobrando un servicio que nada sostiene.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Una asignación lista para entrevistar. `withContract: false` deja a la
 * empresa sin contrato, que es el escenario que este archivo persigue.
 *
 * @return array{assignment: VacancyAssignment, company: Company, companyUser: User, vacancy: Vacancy}
 */
function contractGateSetup(bool $withContract = true): array
{
    $candidateUser = User::factory()->create();
    $candidateUser->assignRole(UserRole::Candidate->value);
    $profile = CandidateProfile::factory()->create([
        'user_id' => $candidateUser->id,
        'state' => CandidateState::Activo->value,
    ]);

    $company = Company::factory()->create();

    if ($withContract) {
        CompanyContract::factory()->create(['company_id' => $company->id]);
    }

    $companyUser = User::factory()->create();
    $companyUser->assignRole(UserRole::CompanyUser->value);
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $companyUser->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::ConCandidatosAsignados->value,
    ]);

    $assignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $profile->id,
    ]);

    return compact('assignment', 'company', 'companyUser', 'vacancy');
}

function contractGateRecruiter(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function contractGatePayload(VacancyAssignment $assignment, array $overrides = []): array
{
    return array_merge([
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addDays(3)->setTime(10, 0)->toIso8601String(),
        'alternate_scheduled_at' => now()->addDays(4)->setTime(16, 0)->toIso8601String(),
        'duration_minutes' => 60,
    ], $overrides);
}

it('refuses to schedule when the company never signed', function (): void {
    contractGateRecruiter();
    ['assignment' => $assignment] = contractGateSetup(withContract: false);

    $this->postJson('/api/v1/interviews', contractGatePayload($assignment))
        ->assertStatus(409)
        ->assertJsonPath('errors.contract.0', 'unsigned');

    expect(Interview::count())->toBe(0);
});

it('schedules once the contract is signed', function (): void {
    contractGateRecruiter();
    ['assignment' => $assignment] = contractGateSetup();

    $this->postJson('/api/v1/interviews', contractGatePayload($assignment))
        ->assertCreated();

    expect(Interview::count())->toBe(1);
});

it('blocks the recruiter too — the gate is about the company, not the caller', function (): void {
    // Quien agenda es HUMAE, y aun así no puede: el instrumento que falta es de
    // la empresa. Un gate que sólo mirara al llamador no protegería nada.
    contractGateRecruiter();
    ['assignment' => $assignment] = contractGateSetup(withContract: false);

    $this->postJson('/api/v1/interviews', contractGatePayload($assignment))
        ->assertStatus(409);
});

it('blocks the company itself when it has not signed', function (): void {
    ['assignment' => $assignment, 'companyUser' => $companyUser] = contractGateSetup(withContract: false);
    Sanctum::actingAs($companyUser);

    $this->postJson('/api/v1/interviews', contractGatePayload($assignment))
        ->assertStatus(409)
        ->assertJsonPath('errors.contract.0', 'unsigned');
});

it('stops counting an annulled contract', function (): void {
    contractGateRecruiter();
    ['assignment' => $assignment, 'company' => $company] = contractGateSetup();

    $contract = CompanyContract::acrossCompanies()->where('company_id', $company->id)->firstOrFail();
    $contract->delete(); // Anular es soft delete: conserva PDF, huella y constancia.

    $this->postJson('/api/v1/interviews', contractGatePayload($assignment))
        ->assertStatus(409)
        ->assertJsonPath('errors.contract.0', 'unsigned');

    expect(Interview::count())->toBe(0);
});

it('does not accept another company contract as its own', function (): void {
    contractGateRecruiter();
    ['assignment' => $assignment] = contractGateSetup(withContract: false);

    // Una empresa distinta sí firmó. No sirve.
    CompanyContract::factory()->create(['company_id' => Company::factory()->create()->id]);

    $this->postJson('/api/v1/interviews', contractGatePayload($assignment))
        ->assertStatus(409);

    expect(Interview::count())->toBe(0);
});

it('guards every interview, not only the first', function (): void {
    contractGateRecruiter();
    ['assignment' => $assignment, 'company' => $company] = contractGateSetup();

    $this->postJson('/api/v1/interviews', contractGatePayload($assignment))
        ->assertCreated();

    CompanyContract::acrossCompanies()->where('company_id', $company->id)->firstOrFail()->delete();

    // Segunda ronda con el contrato anulado a media búsqueda: se bloquea. La
    // comprobación cuesta un `exists()` y cierra esa ventana.
    $this->postJson('/api/v1/interviews', contractGatePayload($assignment, [
        'scheduled_at' => now()->addDays(10)->setTime(10, 0)->toIso8601String(),
        'alternate_scheduled_at' => now()->addDays(11)->setTime(10, 0)->toIso8601String(),
    ]))->assertStatus(409);

    expect(Interview::count())->toBe(1);
});

it('still lets an existing interview be rescheduled after the contract is annulled', function (): void {
    contractGateRecruiter();
    ['assignment' => $assignment, 'company' => $company] = contractGateSetup();

    $created = $this->postJson('/api/v1/interviews', contractGatePayload($assignment))
        ->assertCreated()
        ->json('data.id');

    CompanyContract::acrossCompanies()->where('company_id', $company->id)->firstOrFail()->delete();

    // Reprogramar no es programar: la cita ya existe y moverla es coordinación,
    // no un compromiso nuevo. Dejar a un candidato con una cita imposible de
    // mover sería castigarlo por un trámite de la empresa.
    $this->patchJson("/api/v1/interviews/{$created}", [
        'scheduled_at' => now()->addDays(6)->setTime(9, 0)->toIso8601String(),
        'reason' => 'El entrevistador se enfermó.',
    ])->assertOk();
});
