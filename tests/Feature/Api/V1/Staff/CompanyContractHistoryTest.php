<?php

declare(strict_types=1);

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyContract;
use App\Models\CompanyMember;
use App\Models\User;
use App\Models\Vacancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    Storage::fake('local');

    // Sin apoderado, `currentTerms()` se niega a proponer nada y las entradas
    // pendientes llegan con `blocker` en vez de con términos.
    config()->set('contracts.signatory.name', 'Apoderado HUMAE');
    config()->set('contracts.signatory.title', 'Representante Legal');
});

function staffUser(UserRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * Deja en el disco falso los cinco archivos que el contrato dice tener, para
 * que `available_files` responda que sí y `files` los pueda servir.
 */
function materializeContractFiles(CompanyContract $contract): void
{
    foreach ($contract->storedPaths() as $path) {
        Storage::disk('local')->put($path, 'contenido-de-prueba');
    }
}

it('lista el contrato maestro firmado de una empresa para el admin', function (): void {
    $company = Company::factory()->create();
    $contract = CompanyContract::factory()->for($company)->create();
    materializeContractFiles($contract);

    staffUser(UserRole::Admin);

    $response = $this->getJson("/api/v1/companies/{$company->id}/contracts");

    $response->assertOk()
        ->assertJsonPath('data.0.kind', 'master')
        ->assertJsonPath('data.0.status', 'signed')
        ->assertJsonPath('data.0.is_current', true)
        ->assertJsonPath('data.0.contract.folio', $contract->folio)
        ->assertJsonPath('data.0.contract.available_files.identity', true)
        ->assertJsonPath('meta.summary.signed', 1)
        ->assertJsonPath('meta.summary.pending', 0);
});

it('reporta el maestro como pendiente cuando la empresa no firmó', function (): void {
    $company = Company::factory()->create();

    staffUser(UserRole::Recruiter);

    $this->getJson("/api/v1/companies/{$company->id}/contracts")
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'master')
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.contract', null)
        ->assertJsonPath('meta.summary.pending', 1);
});

it('reporta como pendiente la vacante con honorario propio sin adenda', function (): void {
    $company = Company::factory()->create();
    CompanyContract::factory()->for($company)->create();

    $vacancy = Vacancy::factory()->for($company)->create([
        'title' => 'Backend Senior',
        'fee_percentage' => 18.5,
        'fee_amount' => null,
    ]);

    staffUser(UserRole::Recruiter);

    $response = $this->getJson("/api/v1/companies/{$company->id}/contracts");

    $response->assertOk()
        // Pendientes primero: es lo accionable.
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.kind', 'addendum')
        ->assertJsonPath('data.0.vacancy.id', $vacancy->id)
        ->assertJsonPath('data.0.pending_terms.fee_value', 18.5)
        ->assertJsonPath('meta.summary.pending', 1)
        ->assertJsonPath('meta.summary.signed', 1);
});

it('conserva en el historial el contrato anulado y deja de darlo por vigente', function (): void {
    $company = Company::factory()->create();
    $contract = CompanyContract::factory()->for($company)->create();
    $contract->delete();

    staffUser(UserRole::Admin);

    $response = $this->getJson("/api/v1/companies/{$company->id}/contracts");

    $response->assertOk()
        ->assertJsonPath('meta.summary.voided', 1)
        // Anulado el maestro, la empresa vuelve a deber uno.
        ->assertJsonPath('meta.summary.pending', 1)
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.1.status', 'voided')
        ->assertJsonPath('data.1.is_current', false);
});

it('no deja que un usuario de empresa lea el historial de su propia empresa', function (): void {
    $company = Company::factory()->create();
    CompanyContract::factory()->for($company)->create();

    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);
    Sanctum::actingAs($user);

    // El endpoint es el registro de clientes de HUMAE. La empresa ve el suyo
    // por `/me/company/contract`, que no expone INE ni selfie.
    $this->getJson("/api/v1/companies/{$company->id}/contracts")
        ->assertForbidden();
});

it('esconde la empresa entera de un candidato', function (): void {
    $company = Company::factory()->create();

    staffUser(UserRole::Candidate);

    // 404 y no 403: el `CompanyOwnedScope` deja al candidato sin ninguna empresa
    // visible, así que el binding no resuelve. Es la respuesta correcta — un 403
    // confirmaría que esa empresa existe.
    $this->getJson("/api/v1/companies/{$company->id}/contracts")
        ->assertNotFound();
});

it('sirve la identificación y deja rastro en la bitácora', function (): void {
    $company = Company::factory()->create();
    $contract = CompanyContract::factory()->for($company)->create();
    materializeContractFiles($contract);

    $admin = staffUser(UserRole::Admin);

    $response = $this->get("/api/v1/contracts/{$contract->id}/files/identity")
        ->assertOk();

    // Una INE no se queda en el caché de nadie. Se comprueba por contenido y no
    // por igualdad porque Symfony fusiona la directiva con las suyas.
    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('private');

    $activity = Activity::query()->where('log_name', 'contract-evidence')->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->causer_id)->toBe($admin->id)
        ->and($activity?->properties->get('kind'))->toBe('identity')
        ->and($activity?->properties->get('folio'))->toBe($contract->folio);
});

it('no registra en bitácora la descarga del PDF', function (): void {
    $company = Company::factory()->create();
    $contract = CompanyContract::factory()->for($company)->create();
    materializeContractFiles($contract);

    staffUser(UserRole::Admin);

    $this->get("/api/v1/contracts/{$contract->id}/files/pdf")->assertOk();

    // El PDF es el documento, no un dato personal. Anotarlo también diluiría la
    // bitácora hasta volverla inútil para lo que existe.
    expect(Activity::query()->where('log_name', 'contract-evidence')->count())->toBe(0);
});

it('responde 404 cuando el archivo se perdió del disco', function (): void {
    $company = Company::factory()->create();
    $contract = CompanyContract::factory()->for($company)->create();

    staffUser(UserRole::Admin);

    // Sin `materializeContractFiles`: la fila dice que hay archivo, el disco no.
    $this->getJson("/api/v1/contracts/{$contract->id}/files/pdf")
        ->assertNotFound();
});

it('rechaza una clase de archivo que no existe', function (): void {
    $company = Company::factory()->create();
    $contract = CompanyContract::factory()->for($company)->create();

    staffUser(UserRole::Admin);

    $this->getJson("/api/v1/contracts/{$contract->id}/files/internal-notes")
        ->assertNotFound();
});

it('deja consultar el detalle de un contrato anulado', function (): void {
    $company = Company::factory()->create();
    $contract = CompanyContract::factory()->for($company)->create();
    $contract->delete();

    staffUser(UserRole::Admin);

    $this->getJson("/api/v1/contracts/{$contract->id}")
        ->assertOk()
        ->assertJsonPath('data.is_voided', true)
        ->assertJsonPath('data.folio', $contract->folio);
});

it('ordena el historial por lo accionable y después por jerarquía', function (): void {
    $company = Company::factory()->create();

    // Maestro firmado hace un rato.
    $master = CompanyContract::factory()->for($company)->create([
        'vacancy_id' => null,
        'signed_at' => now()->subHours(3),
    ]);

    // Adenda firmada DESPUÉS del maestro: es la que rompía el orden.
    $signedVacancy = Vacancy::factory()->for($company)->create(['fee_percentage' => 12]);
    $addendum = CompanyContract::factory()->for($company)->create([
        'vacancy_id' => $signedVacancy->id,
        'signed_at' => now(),
    ]);

    // Adenda propuesta y sin firmar.
    Vacancy::factory()->for($company)->create([
        'title' => 'Vacante pendiente',
        'fee_percentage' => 25,
    ]);

    staffUser(UserRole::Admin);

    $this->getJson("/api/v1/companies/{$company->id}/contracts")
        ->assertOk()
        // Lo pendiente arriba: es lo único sobre lo que alguien puede actuar.
        ->assertJsonPath('data.0.status', 'pending')
        // Después el maestro, aunque la adenda se haya firmado más tarde.
        ->assertJsonPath('data.1.contract.id', $master->id)
        ->assertJsonPath('data.2.contract.id', $addendum->id);
});

it('nunca publica la ruta de la firma del apoderado en los términos', function (): void {
    $company = Company::factory()->create();
    $contract = CompanyContract::factory()->for($company)->create([
        'terms' => [
            'version' => '2026.1',
            'fee_kind' => 'percentage_annual_gross',
            'fee_value' => 12.0,
            'jurisdiction' => 'Querétaro',
            'signatory' => ['name' => 'Apoderado', 'title' => 'Rep. Legal'],
            'signature_path' => 'contract-settings/firma-secreta.png',
        ],
    ]);

    staffUser(UserRole::Admin);

    $this->getJson("/api/v1/contracts/{$contract->id}")
        ->assertOk()
        ->assertJsonMissing(['signature_path' => 'contract-settings/firma-secreta.png']);
});
