<?php

declare(strict_types=1);

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyContract;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\CompanyContractService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    Storage::fake('local');

    // El apoderado de HUMAE no tiene default en config: sin él el servicio se
    // niega a emitir (un contrato firmado por una sola parte no sirve).
    config()->set('contracts.signatory.name', 'Apoderado HUMAE');
    config()->set('contracts.signatory.title', 'Representante Legal');
    config()->set('services.cincel.jwt', 'test-jwt');
    config()->set('services.cincel.retry_delay_ms', 0);

    // El fake de CINCEL se declara por escenario, no aquí: `Http::fake()` acumula
    // stubs y gana el primero registrado, así que un stub global impediría que un
    // test simule la caída del servicio.
    Http::preventStrayRequests();
});

/**
 * CINCEL responde con la constancia al primer intento.
 */
function fakeCincelOk(): void
{
    Http::fake(['api.cincel.digital/*' => Http::response('fake-asn1-token', 200)]);
}

/**
 * @param  CompanyMemberRole  $role  rol dentro de la empresa
 */
function companyUserFor(Company $company, CompanyMemberRole $role = CompanyMemberRole::Owner): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);

    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => $role->value,
    ]);

    Sanctum::actingAs($user);

    return $user;
}

/**
 * @return array<string, mixed>
 */
function signaturePayload(): array
{
    return [
        'signature' => UploadedFile::fake()->image('signature.png', 600, 200),
        'identity' => UploadedFile::fake()->image('ine.jpg', 800, 500),
        'selfie' => UploadedFile::fake()->image('selfie.jpg', 600, 600),
        'signer_title' => 'Directora de Capital Humano',
        'accept_privacy' => '1',
        'accept_terms' => '1',
    ];
}

it('reports the contract as unsigned before anything is signed', function (): void {
    $company = Company::factory()->create();
    companyUserFor($company);

    $this->getJson('/api/v1/me/company/contract')
        ->assertOk()
        ->assertJsonPath('data', null)
        ->assertJsonPath('meta.is_signed', false)
        ->assertJsonPath('meta.can_sign', true);
});

it('serves an unsigned preview of the contract with the current terms', function (): void {
    $company = Company::factory()->create();
    companyUserFor($company);

    $response = $this->get('/api/v1/me/company/contract/preview');

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    // El borrador se devuelve en memoria, no como stream: no persiste nada que
    // se pueda transmitir desde disco.
    expect($response->getContent())->toStartWith('%PDF-');

    // Un borrador no persiste nada.
    expect(CompanyContract::acrossCompanies()->count())->toBe(0);
});

it('exposes the pending terms so the wizard can show real figures', function (): void {
    $company = Company::factory()->create();
    companyUserFor($company);

    $this->getJson('/api/v1/me/company/contract')
        ->assertOk()
        ->assertJsonPath('meta.pending_terms.fee_kind', 'percentage_annual_gross')
        ->assertJsonPath('meta.pending_terms.warranty_days', 90)
        ->assertJsonPath('meta.pending_terms.signatory.name', 'Apoderado HUMAE');
});

it('stops offering a preview once the contract is signed', function (): void {
    fakeCincelOk();
    $company = Company::factory()->create();
    companyUserFor($company);

    $this->postJson('/api/v1/me/company/contract', signaturePayload())->assertCreated();

    $this->getJson('/api/v1/me/company/contract')
        ->assertOk()
        ->assertJsonPath('meta.pending_terms', null)
        ->assertJsonPath('meta.preview_url', null)
        ->assertJsonPath('meta.can_sign', false);
});

it('signs the contract, stores every artifact privately and stamps it with CINCEL', function (): void {
    fakeCincelOk();
    $company = Company::factory()->create(['legal_name' => 'Manufacturas del Bajío S.A. de C.V.']);
    $user = companyUserFor($company);

    $response = $this->postJson('/api/v1/me/company/contract', signaturePayload());

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_timestamped', true)
        ->assertJsonPath('data.signer.title', 'Directora de Capital Humano')
        ->assertJsonPath('data.terms.fee_kind', 'percentage_annual_gross');

    $contract = CompanyContract::acrossCompanies()->firstOrFail();

    expect($contract->company_id)->toBe($company->id)
        ->and($contract->signed_by_user_id)->toBe($user->id)
        ->and($contract->folio)->toStartWith('HUMAE-CTR-')
        ->and($contract->pdf_hash)->toHaveLength(64)
        ->and($contract->signed_ip)->not->toBeNull()
        ->and($contract->terms_accepted_at)->not->toBeNull()
        ->and($contract->privacy_accepted_at)->not->toBeNull();

    // Todo en el disco privado: son datos personales y un contrato.
    $disk = Storage::disk('local');
    expect($disk->exists($contract->pdf_path))->toBeTrue()
        ->and($disk->exists($contract->signature_path))->toBeTrue()
        ->and($disk->exists($contract->identity_path))->toBeTrue()
        ->and($disk->exists($contract->selfie_path))->toBeTrue()
        ->and($disk->exists((string) $contract->timestamp_path))->toBeTrue();

    // Lo generado es un PDF de verdad.
    expect($disk->get($contract->pdf_path))->toStartWith('%PDF-');
});

it('hashes the base64 of the stored pdf, matching RED1A1 so a constancia verifies the same', function (): void {
    fakeCincelOk();
    $company = Company::factory()->create();
    companyUserFor($company);

    $this->postJson('/api/v1/me/company/contract', signaturePayload())->assertCreated();

    $contract = CompanyContract::acrossCompanies()->firstOrFail();
    $stored = (string) Storage::disk('local')->get($contract->pdf_path);

    expect($contract->pdf_hash)->toBe(hash('sha256', base64_encode($stored)));
});

it('snapshots the commercial terms so a later config change does not rewrite a signed contract', function (): void {
    fakeCincelOk();
    $company = Company::factory()->create();
    companyUserFor($company);

    $this->postJson('/api/v1/me/company/contract', signaturePayload())->assertCreated();

    // HUMAE renegocia sus tarifas después de la firma.
    config()->set('contracts.fee_value', 18);
    config()->set('contracts.warranty_days', 30);

    $contract = CompanyContract::acrossCompanies()->firstOrFail();

    // Comparación numérica: el round-trip por la columna JSON no preserva el
    // tipo float, y lo que importa es el valor congelado, no su representación.
    expect((float) $contract->terms['fee_value'])->toBe(12.0)
        ->and((int) $contract->terms['warranty_days'])->toBe(90);
});

it('keeps the signature when CINCEL is down and leaves the constancia pending', function (): void {
    Http::fake(['api.cincel.digital/*' => Http::response('service unavailable', 503)]);

    $company = Company::factory()->create();
    companyUserFor($company);

    $this->postJson('/api/v1/me/company/contract', signaturePayload())
        ->assertCreated()
        ->assertJsonPath('data.is_timestamped', false);

    $contract = CompanyContract::acrossCompanies()->firstOrFail();

    expect($contract->timestamp_path)->toBeNull()
        ->and($contract->timestamped_at)->toBeNull()
        // La firma sí ocurrió: el contrato existe y el PDF está guardado.
        ->and(Storage::disk('local')->exists($contract->pdf_path))->toBeTrue();
});

it('retries the CINCEL constancia while it answers 202', function (): void {
    Http::fake([
        'api.cincel.digital/*' => Http::sequence()
            ->push('', 202)
            ->push('', 202)
            ->push('fake-asn1-token', 200),
    ]);

    $company = Company::factory()->create();
    companyUserFor($company);

    $this->postJson('/api/v1/me/company/contract', signaturePayload())
        ->assertCreated()
        ->assertJsonPath('data.is_timestamped', true);
});

it('refuses a second contract for the same company', function (): void {
    fakeCincelOk();
    $company = Company::factory()->create();
    companyUserFor($company);

    $this->postJson('/api/v1/me/company/contract', signaturePayload())->assertCreated();

    $this->postJson('/api/v1/me/company/contract', signaturePayload())
        ->assertStatus(409)
        ->assertJsonPath('success', false);

    expect(CompanyContract::acrossCompanies()->count())->toBe(1);
});

it('does not let a viewer sign on behalf of the company', function (): void {
    $company = Company::factory()->create();
    companyUserFor($company, CompanyMemberRole::Viewer);

    $this->postJson('/api/v1/me/company/contract', signaturePayload())
        ->assertStatus(403);

    expect(CompanyContract::acrossCompanies()->count())->toBe(0);
});

it('rejects a submission with missing files or unchecked acceptances', function (): void {
    $company = Company::factory()->create();
    companyUserFor($company);

    $this->postJson('/api/v1/me/company/contract', [
        'signer_title' => 'Directora de Capital Humano',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['signature', 'identity', 'selfie', 'accept_privacy', 'accept_terms']);

    $payload = signaturePayload();
    $payload['accept_terms'] = '0';

    $this->postJson('/api/v1/me/company/contract', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['accept_terms']);
});

it('rejects a signature that is not a png', function (): void {
    $company = Company::factory()->create();
    companyUserFor($company);

    $payload = signaturePayload();
    $payload['signature'] = UploadedFile::fake()->create('signature.pdf', 100, 'application/pdf');

    $this->postJson('/api/v1/me/company/contract', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['signature']);
});

it('answers 404 when the caller is not linked to any company', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me/company/contract')->assertStatus(404);
    $this->postJson('/api/v1/me/company/contract', signaturePayload())->assertStatus(404);
});

it('keeps candidates out of the company contract endpoints', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me/company/contract')->assertStatus(403);
    $this->postJson('/api/v1/me/company/contract', signaturePayload())->assertStatus(403);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/me/company/contract')->assertStatus(401);
});

it('downloads the stored pdf, not a regenerated one', function (): void {
    fakeCincelOk();
    $company = Company::factory()->create();
    companyUserFor($company);

    $this->postJson('/api/v1/me/company/contract', signaturePayload())->assertCreated();

    $contract = CompanyContract::acrossCompanies()->firstOrFail();
    $stored = (string) Storage::disk('local')->get($contract->pdf_path);

    $response = $this->get('/api/v1/me/company/contract/download');
    $response->assertOk();

    // Byte a byte: si sirviera un PDF regenerado, el CreationDate de DomPDF lo
    // haría distinto y dejaría de cuadrar con la constancia.
    expect($response->streamedContent())->toBe($stored);
});

it('exposes the contract state on /me/company so the gate resolves in one request', function (): void {
    fakeCincelOk();
    $company = Company::factory()->create();
    companyUserFor($company);

    $this->getJson('/api/v1/me/company')
        ->assertOk()
        ->assertJsonPath('data.contract.is_signed', false);

    $this->postJson('/api/v1/me/company/contract', signaturePayload())->assertCreated();

    $this->getJson('/api/v1/me/company')
        ->assertOk()
        ->assertJsonPath('data.contract.is_signed', true)
        ->assertJsonPath('data.contract.is_timestamped', true);
});

it('never leaks file paths or the pdf hash through the api', function (): void {
    fakeCincelOk();
    $company = Company::factory()->create();
    companyUserFor($company);

    $response = $this->postJson('/api/v1/me/company/contract', signaturePayload());
    $response->assertCreated();

    $body = $response->getContent();

    expect($body)->not->toContain('signature_path')
        ->and($body)->not->toContain('identity_path')
        ->and($body)->not->toContain('selfie_path')
        ->and($body)->not->toContain('pdf_path')
        ->and($body)->not->toContain('pdf_hash');
});

it('re-stamps a contract whose constancia never arrived, and is a no-op once stamped', function (): void {
    // Una sola secuencia para las dos llamadas: los stubs de `Http::fake()` se
    // acumulan y gana el primero registrado, así que un segundo `fake()` no
    // reemplazaría al primero.
    Http::fake([
        'api.cincel.digital/*' => Http::sequence()
            ->push('down', 503)              // firma: CINCEL caído
            ->push('recovered-asn1', 200),   // reintento: CINCEL de vuelta
    ]);

    $company = Company::factory()->create();
    companyUserFor($company);
    $this->postJson('/api/v1/me/company/contract', signaturePayload())->assertCreated();

    $contract = CompanyContract::acrossCompanies()->firstOrFail();
    expect($contract->timestamp_path)->toBeNull();

    $service = app(CompanyContractService::class);
    $hashBefore = $contract->pdf_hash;

    expect($service->retryTimestamp($contract))->toBeTrue();

    $contract->refresh();

    expect($contract->timestamp_path)->not->toBeNull()
        ->and($contract->timestamped_at)->not->toBeNull()
        ->and($contract->pdf_hash)->toBe($hashBefore)
        ->and(Storage::disk('local')->exists((string) $contract->timestamp_path))->toBeTrue();

    // Segundo reintento: no vuelve a pedir nada.
    expect($service->retryTimestamp($contract))->toBeTrue();
});

it('allocates sequential folios across companies', function (): void {
    fakeCincelOk();
    $first = Company::factory()->create();
    companyUserFor($first);
    $this->postJson('/api/v1/me/company/contract', signaturePayload())->assertCreated();

    $second = Company::factory()->create();
    companyUserFor($second);
    $this->postJson('/api/v1/me/company/contract', signaturePayload())->assertCreated();

    $folios = CompanyContract::acrossCompanies()->orderBy('id')->pluck('folio')->all();
    $year = now()->format('Y');

    expect($folios)->toBe([
        "HUMAE-CTR-{$year}-000001",
        "HUMAE-CTR-{$year}-000002",
    ]);
});
