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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as IlluminateView;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    Storage::fake('local');

    config()->set('contracts.signatory.name', 'Apoderado HUMAE');
    config()->set('contracts.signatory.title', 'Representante Legal');
    config()->set('services.cincel.jwt', 'test-jwt');
    config()->set('services.cincel.retry_delay_ms', 0);

    Http::preventStrayRequests();
    Http::fake(['api.cincel.digital/*' => Http::response('fake-asn1-token', 200)]);
});

/**
 * Una empresa con su contrato maestro ya firmado y una vacante con honorarios
 * propios: el escenario donde la adenda tiene sentido.
 *
 * @return array{0: Company, 1: User, 2: Vacancy}
 */
function companyWithMasterContract(float $feePercentage = 20): array
{
    $company = Company::factory()->create();

    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    Sanctum::actingAs($user);

    test()->postJson('/api/v1/me/company/contract', [
        'signature' => UploadedFile::fake()->image('signature.png', 600, 200),
        'identity' => UploadedFile::fake()->image('ine.jpg', 800, 500),
        'selfie' => UploadedFile::fake()->image('selfie.jpg', 600, 600),
        'signer_title' => 'Directora de Capital Humano',
        'accept_privacy' => '1',
        'accept_terms' => '1',
    ])->assertCreated();

    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'fee_percentage' => $feePercentage,
    ]);

    return [$company, $user, $vacancy];
}

/**
 * Lo mínimo que pide la adenda a quien ya está acreditado: leerla y firmarla.
 *
 * @return array<string, mixed>
 */
function addendumPayload(): array
{
    return [
        'signature' => UploadedFile::fake()->image('signature.png', 600, 200),
        'accept_terms' => '1',
    ];
}

it('signs the addendum with just a signature when the same person already signed the master', function (): void {
    [, $user, $vacancy] = companyWithMasterContract();

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract", addendumPayload())
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.terms.fee_value', 20);

    $addendum = CompanyContract::acrossCompanies()->whereNotNull('vacancy_id')->firstOrFail();

    expect($addendum->vacancy_id)->toBe($vacancy->id)
        ->and($addendum->signed_by_user_id)->toBe($user->id)
        // El cargo se hereda del maestro: no se le volvió a preguntar.
        ->and($addendum->signer_title)->toBe('Directora de Capital Humano')
        ->and($addendum->folio)->toStartWith('HUMAE-CTR-');
});

it('carries the privacy acceptance of the master instead of stamping a consent nobody gave today', function (): void {
    [, , $vacancy] = companyWithMasterContract();

    $master = CompanyContract::acrossCompanies()->whereNull('vacancy_id')->firstOrFail();

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract", addendumPayload())
        ->assertCreated();

    $addendum = CompanyContract::acrossCompanies()->whereNotNull('vacancy_id')->firstOrFail();

    expect($addendum->privacy_accepted_at?->timestamp)
        ->toBe($master->privacy_accepted_at?->timestamp)
        // La aceptación del documento sí es de hoy: es lo único que se leyó.
        ->and($addendum->terms_accepted_at)->not->toBeNull();
});

it('copies the identity evidence instead of pointing at the master files', function (): void {
    [, , $vacancy] = companyWithMasterContract();

    $master = CompanyContract::acrossCompanies()->whereNull('vacancy_id')->firstOrFail();

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract", addendumPayload())
        ->assertCreated();

    $addendum = CompanyContract::acrossCompanies()->whereNotNull('vacancy_id')->firstOrFail();
    $disk = Storage::disk('local');

    expect($addendum->identity_path)->not->toBe($master->identity_path)
        ->and($addendum->selfie_path)->not->toBe($master->selfie_path)
        ->and($disk->exists($addendum->identity_path))->toBeTrue()
        ->and($disk->exists($addendum->selfie_path))->toBeTrue()
        // Mismo contenido: es la misma persona acreditada, no un archivo vacío.
        ->and($disk->get($addendum->identity_path))->toBe($disk->get($master->identity_path));

    // Y el trazo de la firma sí es nuevo: es el acto que obliga.
    expect($addendum->signature_path)->not->toBe($master->signature_path);
});

it('survives the master being voided, because voiding wipes the master files from disk', function (): void {
    [, , $vacancy] = companyWithMasterContract();

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract", addendumPayload())
        ->assertCreated();

    $master = CompanyContract::acrossCompanies()->whereNull('vacancy_id')->firstOrFail();
    $addendum = CompanyContract::acrossCompanies()->whereNotNull('vacancy_id')->firstOrFail();

    // Lo que hace `VoidCompanyContract`: borra del disco todo lo del maestro.
    $disk = Storage::disk('local');
    foreach ($master->storedPaths() as $path) {
        $disk->delete($path);
    }

    // La adenda sigue vigente y sostiene una factura: su evidencia no se fue
    // con el maestro.
    expect($disk->exists($addendum->identity_path))->toBeTrue()
        ->and($disk->exists($addendum->selfie_path))->toBeTrue()
        ->and($disk->exists($addendum->pdf_path))->toBeTrue();
});

it('demands the full identity when a different person signs the addendum', function (): void {
    [$company, , $vacancy] = companyWithMasterContract();

    // Otro manager de la misma empresa: puede firmar, pero de él nadie acreditó
    // nada todavía.
    $other = User::factory()->create();
    $other->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $other->id,
        'role' => CompanyMemberRole::Manager->value,
    ]);
    Sanctum::actingAs($other);

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract", addendumPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['identity', 'selfie', 'signer_title']);

    // Con los archivos, firma sin problema.
    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract", [
        'signature' => UploadedFile::fake()->image('signature.png', 600, 200),
        'identity' => UploadedFile::fake()->image('ine.jpg', 800, 500),
        'selfie' => UploadedFile::fake()->image('selfie.jpg', 600, 600),
        'signer_title' => 'Gerente de Compras',
        'accept_terms' => '1',
    ])->assertCreated();

    $addendum = CompanyContract::acrossCompanies()->whereNotNull('vacancy_id')->firstOrFail();

    expect($addendum->signed_by_user_id)->toBe($other->id)
        ->and($addendum->signer_title)->toBe('Gerente de Compras');
});

it('still requires accepting the addendum itself', function (): void {
    [, , $vacancy] = companyWithMasterContract();

    $payload = addendumPayload();
    $payload['accept_terms'] = '0';

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['accept_terms']);

    // Y sin firma tampoco: el trazo nunca se recicla.
    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract", ['accept_terms' => '1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['signature']);
});

it('tells the wizard how many steps to ask for', function (): void {
    [$company, , $vacancy] = companyWithMasterContract();

    $this->getJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract")
        ->assertOk()
        ->assertJsonPath('meta.identity_on_file', true)
        ->assertJsonPath('meta.signer_title', 'Directora de Capital Humano')
        ->assertJsonPath('meta.can_sign', true)
        ->assertJsonPath('meta.master_contract_signed', true);

    // Otra persona de la misma empresa: no está acreditada, pide todo.
    $other = User::factory()->create();
    $other->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $other->id,
        'role' => CompanyMemberRole::Manager->value,
    ]);
    Sanctum::actingAs($other);

    $this->getJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract")
        ->assertOk()
        ->assertJsonPath('meta.identity_on_file', false)
        ->assertJsonPath('meta.signer_title', null)
        ->assertJsonPath('meta.can_sign', true);
});

it('does not let a viewer sign the addendum', function (): void {
    [$company, , $vacancy] = companyWithMasterContract();

    $viewer = User::factory()->create();
    $viewer->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $viewer->id,
        'role' => CompanyMemberRole::Viewer->value,
    ]);
    Sanctum::actingAs($viewer);

    $this->getJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract")
        ->assertOk()
        ->assertJsonPath('meta.can_sign', false);

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract", addendumPayload())
        ->assertStatus(403);

    expect(CompanyContract::acrossCompanies()->whereNotNull('vacancy_id')->count())->toBe(0);
});

it('refuses an addendum before the master contract exists', function (): void {
    $company = Company::factory()->create();

    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);
    Sanctum::actingAs($user);

    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'fee_percentage' => 20,
    ]);

    // Sin acreditación previa el Form Request pide los tres archivos, así que se
    // mandan: lo que tiene que frenar acá es la regla de negocio, no el 422.
    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract", [
        'signature' => UploadedFile::fake()->image('signature.png', 600, 200),
        'identity' => UploadedFile::fake()->image('ine.jpg', 800, 500),
        'selfie' => UploadedFile::fake()->image('selfie.jpg', 600, 600),
        'signer_title' => 'Directora de Capital Humano',
        'accept_terms' => '1',
    ])->assertStatus(409);

    $this->getJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract")
        ->assertOk()
        ->assertJsonPath('meta.master_contract_signed', false)
        ->assertJsonPath('meta.can_sign', false);
});

it('refuses a second addendum for the same vacancy', function (): void {
    [, , $vacancy] = companyWithMasterContract();

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract", addendumPayload())
        ->assertCreated();

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract", addendumPayload())
        ->assertStatus(409);

    expect(CompanyContract::acrossCompanies()->whereNotNull('vacancy_id')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| El documento
|--------------------------------------------------------------------------
|
| La adenda reutilizaba el Blade del contrato maestro y salía titulada «acceso
| a plataforma», con una cláusula Primera que regulaba un acceso ya pactado y
| sin nombrar nunca la vacante. Estos tests fijan lo que el documento tiene que
| decir para ser una adenda y no una copia con otro folio.
|
*/

/**
 * Renderiza el endpoint y devuelve [nombre de la plantilla, HTML].
 *
 * No se busca el texto dentro del PDF a propósito: DejaVu Sans va incrustada
 * como subconjunto, así que las frases se escriben como índices de glifo y no
 * queda ASCII que buscar. Con un composer se captura la vista que el servicio
 * eligió y los datos que le pasó, y se rinde ese mismo par — se prueba qué
 * plantilla se usó y qué dice, sin pelear con las tripas del PDF.
 *
 * @return array{0: string, 1: string}
 */
function renderedContract(callable $request): array
{
    $captured = null;

    View::composer('pdf.*', function (IlluminateView $view) use (&$captured): void {
        // El primero gana: los parciales también disparan el composer.
        $captured ??= [$view->name(), $view->getData()];
    });

    $request();

    if ($captured === null) {
        return ['', ''];
    }

    [$name, $data] = $captured;

    return [$name, View::make($name, $data)->render()];
}

it('names the vacancy in the addendum instead of reusing the master wording', function (): void {
    [, , $vacancy] = companyWithMasterContract();
    $vacancy->forceFill(['title' => 'Contador Senior'])->save();

    [$template, $html] = renderedContract(
        fn () => $this->get("/api/v1/me/company/vacancies/{$vacancy->id}/contract/preview")->assertOk(),
    );

    expect($template)->toBe('pdf.vacancy-fee-addendum')
        ->and($html)->toContain('ADENDA DE HONORARIOS')
        ->and($html)->toContain('CONTADOR SENIOR')
        ->and($html)->toContain($vacancy->code)
        // Y no el título del maestro, que anunciaba algo ya pactado.
        ->and($html)->not->toContain('(ACCESO A PLATAFORMA)');
});

it('anchors the addendum to the master contract it hangs from', function (): void {
    [$company, , $vacancy] = companyWithMasterContract();
    $master = CompanyContract::masterFor($company->id);

    [, $html] = renderedContract(
        fn () => $this->get("/api/v1/me/company/vacancies/{$vacancy->id}/contract/preview"),
    );

    // Sin nombrar el instrumento del que cuelga, la adenda queda huérfana y su
    // alcance es discutible.
    expect($html)->toContain('Antecedentes')
        ->and($html)->toContain((string) $master?->folio)
        ->and($html)->toContain('EL CONTRATO');
});

it('says the fee applies to this vacancy only, and that the rest stands', function (): void {
    [, , $vacancy] = companyWithMasterContract();

    [, $html] = renderedContract(
        fn () => $this->get("/api/v1/me/company/vacancies/{$vacancy->id}/contract/preview"),
    );

    expect($html)->toContain('no aplica a ninguna otra vacante')
        ->and($html)->toContain('permanecen en sus términos')
        // El honorario propio de la vacante, no el del contrato maestro.
        ->and($html)->toContain('20% (por ciento) del sueldo bruto anualizado');
});

it('keeps the master contract on its own wording', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);
    Sanctum::actingAs($user);

    [$template, $html] = renderedContract(
        fn () => $this->get('/api/v1/me/company/contract/preview')->assertOk(),
    );

    // El maestro no se contagió del cambio: sigue siendo el de acceso.
    expect($template)->toBe('pdf.company-contract')
        ->and($html)->toContain('(ACCESO A PLATAFORMA)')
        ->and($html)->not->toContain('ADENDA DE HONORARIOS');
});

it('keeps the master contract untouched when an addendum is signed', function (): void {
    [$company, , $vacancy] = companyWithMasterContract();

    $this->postJson("/api/v1/me/company/vacancies/{$vacancy->id}/contract", addendumPayload())
        ->assertCreated();

    // El gate de entrevistas consulta el maestro: una adenda no lo sustituye ni
    // lo desplaza.
    $master = CompanyContract::masterFor($company->id);

    expect($master)->not->toBeNull()
        ->and($master?->vacancy_id)->toBeNull();
});
