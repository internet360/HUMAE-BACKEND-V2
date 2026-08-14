<?php

declare(strict_types=1);

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyContract;
use App\Models\CompanyMember;
use App\Models\User;
use App\Models\Vacancy;
use App\Notifications\VacancyFeeProposedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

/**
 * Una empresa con owner, manager y lector, más una vacante sin honorarios
 * propios.
 *
 * @return array{0: Vacancy, 1: User, 2: User, 3: User}
 */
function feeProposalScenario(): array
{
    $company = Company::factory()->create();

    $owner = User::factory()->create();
    $owner->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $owner->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    $manager = User::factory()->create();
    $manager->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $manager->id,
        'role' => CompanyMemberRole::Manager->value,
    ]);

    $viewer = User::factory()->create();
    $viewer->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $viewer->id,
        'role' => CompanyMemberRole::Viewer->value,
    ]);

    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'fee_percentage' => null,
        'fee_amount' => null,
    ]);

    return [$vacancy, $owner, $manager, $viewer];
}

function actAsFeeRecruiter(): User
{
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    return $recruiter;
}

it('tells the company when HUMAE proposes fees for one of its vacancies', function (): void {
    [$vacancy, $owner, $manager, $viewer] = feeProposalScenario();
    actAsFeeRecruiter();

    $this->patchJson("/api/v1/vacancies/{$vacancy->id}", ['fee_percentage' => 20])
        ->assertOk();

    // El estado «propuesto y sin firmar» sólo se descubría entrando al detalle
    // de esa vacante. Ahora sale a buscar a quien puede firmarlo.
    Notification::assertSentTo([$owner, $manager], VacancyFeeProposedNotification::class);

    // Al lector no: no puede firmar, así que avisarle es ruido.
    Notification::assertNotSentTo($viewer, VacancyFeeProposedNotification::class);
});

it('carries the actual figure so nobody has to click to find out', function (): void {
    [$vacancy, $owner] = feeProposalScenario();
    actAsFeeRecruiter();

    $this->patchJson("/api/v1/vacancies/{$vacancy->id}", ['fee_percentage' => 20])
        ->assertOk();

    Notification::assertSentTo(
        $owner,
        VacancyFeeProposedNotification::class,
        function (VacancyFeeProposedNotification $notification) use ($owner): bool {
            $payload = $notification->toArray($owner);

            return str_contains((string) $payload['fee'], '20%')
                && $payload['vacancy_id'] === $notification->vacancy->id;
        },
    );
});

it('does not notify again when the figure did not move', function (): void {
    [$vacancy, $owner] = feeProposalScenario();
    actAsFeeRecruiter();

    $this->patchJson("/api/v1/vacancies/{$vacancy->id}", ['fee_percentage' => 20])->assertOk();
    $this->patchJson("/api/v1/vacancies/{$vacancy->id}", ['fee_percentage' => 20])->assertOk();

    // Un aviso repetido por una edición que no cambió el número entrena a la
    // gente a ignorar los avisos.
    Notification::assertSentToTimes($owner, VacancyFeeProposedNotification::class, 1);
});

it('does not notify when the vacancy goes back to the master contract', function (): void {
    [$vacancy, $owner] = feeProposalScenario();
    actAsFeeRecruiter();

    $vacancy->forceFill(['fee_percentage' => 20])->save();

    $this->patchJson("/api/v1/vacancies/{$vacancy->id}", ['fee_percentage' => null])
        ->assertOk();

    // No hay nada que firmar: vuelve a la regla que la empresa ya aceptó.
    Notification::assertNothingSentTo($owner);
});

it('does not chase a signature that the company already gave', function (): void {
    [$vacancy, $owner] = feeProposalScenario();

    CompanyContract::factory()->create([
        'company_id' => $vacancy->company_id,
        'vacancy_id' => $vacancy->id,
    ]);

    actAsFeeRecruiter();

    $this->patchJson("/api/v1/vacancies/{$vacancy->id}", ['fee_percentage' => 25])
        ->assertOk();

    // Con adenda firmada, esta columna ya no factura nada: pedir la firma otra
    // vez sería pedir algo que no corresponde.
    Notification::assertNothingSentTo($owner);
});

it('flags the pending signature on the vacancy so a listing can show it', function (): void {
    [$vacancy, $owner] = feeProposalScenario();

    Sanctum::actingAs($owner);
    $this->getJson("/api/v1/me/company/vacancies/{$vacancy->id}")
        ->assertOk()
        ->assertJsonPath('data.fee_addendum_pending', false);

    actAsFeeRecruiter();
    $this->patchJson("/api/v1/vacancies/{$vacancy->id}", ['fee_percentage' => 20])->assertOk();

    Sanctum::actingAs($owner);
    $this->getJson("/api/v1/me/company/vacancies/{$vacancy->id}")
        ->assertOk()
        ->assertJsonPath('data.fee_addendum_pending', true);

    $this->getJson('/api/v1/me/company/vacancies')
        ->assertOk()
        ->assertJsonPath('data.0.fee_addendum_pending', true);
});

it('stops flagging once the addendum is signed', function (): void {
    [$vacancy, $owner] = feeProposalScenario();
    $vacancy->forceFill(['fee_percentage' => 20])->save();

    CompanyContract::factory()->create([
        'company_id' => $vacancy->company_id,
        'vacancy_id' => $vacancy->id,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/me/company/vacancies/{$vacancy->id}")
        ->assertOk()
        ->assertJsonPath('data.fee_addendum_pending', false);
});

it('refuses outright when the company tries to set its own fees', function (): void {
    [$vacancy, $owner] = feeProposalScenario();
    Sanctum::actingAs($owner);

    // 403 y no un 422 ni un descarte silencioso: `fee_percentage` está en
    // `staffOnlyFields`, y «este campo no es tuyo» es una respuesta de permisos.
    // Quien paga no fija la base de su propio cobro, ni siquiera intentándolo.
    $this->patchJson("/api/v1/me/company/vacancies/{$vacancy->id}", [
        'fee_percentage' => 1,
    ])->assertStatus(403);

    expect($vacancy->fresh()->fee_percentage)->toBeNull();
    Notification::assertNothingSentTo($owner);
});
