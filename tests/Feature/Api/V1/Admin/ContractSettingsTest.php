<?php

declare(strict_types=1);

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyContract;
use App\Models\CompanyMember;
use App\Models\ContractSetting;
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
    Http::preventStrayRequests();
});

function actingAsAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    Sanctum::actingAs($admin);

    return $admin;
}

/**
 * @return array<string, mixed>
 */
function contractSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'provider_name' => 'Humae Consultoría de RH',
        'signatory_name' => 'Carlos Pérez',
        'signatory_title' => 'CEO de HUMAE',
        'fee_kind' => 'percentage_annual_gross',
        'fee_value' => 15,
        'payment_days' => 10,
        'payment_day_kind' => 'naturales',
        'warranty_days' => 60,
        'city' => 'Querétaro',
        'jurisdiction' => 'la Ciudad de México, Estados Unidos Mexicanos',
    ], $overrides);
}

it('siembra la configuración desde config la primera vez que se consulta', function (): void {
    config()->set('contracts.fee_value', 12);
    config()->set('contracts.warranty_days', 90);
    config()->set('contracts.signatory.name', 'Apoderado del env');

    expect(ContractSetting::count())->toBe(0);

    actingAsAdmin();

    $this->getJson('/api/v1/admin/contract-settings')
        ->assertOk()
        ->assertJsonPath('data.fee_value', 12)
        ->assertJsonPath('data.warranty_days', 90)
        ->assertJsonPath('data.signatory_name', 'Apoderado del env')
        ->assertJsonPath('data.version', 1);

    // Un solo registro, siempre.
    expect(ContractSetting::count())->toBe(1);
});

it('guarda las condiciones y sube la versión', function (): void {
    $admin = actingAsAdmin();

    $this->putJson('/api/v1/admin/contract-settings', contractSettingsPayload())
        ->assertOk()
        ->assertJsonPath('data.fee_value', 15)
        ->assertJsonPath('data.payment_days', 10)
        ->assertJsonPath('data.payment_day_kind', 'naturales')
        ->assertJsonPath('data.warranty_days', 60)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.is_ready_to_issue', true);

    $settings = ContractSetting::current();

    expect($settings->fee_value)->toBe(15.0)
        ->and($settings->updated_by_user_id)->toBe($admin->id);
});

it('no sube la versión si se guarda sin cambiar nada', function (): void {
    actingAsAdmin();

    $payload = contractSettingsPayload();

    $this->putJson('/api/v1/admin/contract-settings', $payload)
        ->assertOk()
        ->assertJsonPath('data.version', 2);

    // Reenviar lo mismo no debería contar como una condición nueva.
    $this->putJson('/api/v1/admin/contract-settings', $payload)
        ->assertOk()
        ->assertJsonPath('data.version', 2);
});

it('limpia el importe en letra al dejar de cobrar monto fijo', function (): void {
    actingAsAdmin();

    $this->putJson('/api/v1/admin/contract-settings', contractSettingsPayload([
        'fee_kind' => 'fixed_amount',
        'fee_value' => 35000,
        'fee_amount_words' => 'treinta y cinco mil pesos 00/100 M.N.',
    ]))->assertOk()->assertJsonPath('data.fee_amount_words', 'treinta y cinco mil pesos 00/100 M.N.');

    $this->putJson('/api/v1/admin/contract-settings', contractSettingsPayload([
        'fee_kind' => 'percentage_annual_gross',
        'fee_value' => 12,
    ]))->assertOk()->assertJsonPath('data.fee_amount_words', null);
});

it('exige el importe en letra cuando el cobro es un monto fijo', function (): void {
    actingAsAdmin();

    $this->putJson('/api/v1/admin/contract-settings', contractSettingsPayload([
        'fee_kind' => 'fixed_amount',
        'fee_value' => 35000,
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['fee_amount_words']);
});

it('rechaza condiciones que dejarían un contrato inválido', function (): void {
    actingAsAdmin();

    $this->putJson('/api/v1/admin/contract-settings', contractSettingsPayload([
        'signatory_name' => '',
        'fee_value' => 0,
        'warranty_days' => 0,
        'jurisdiction' => '',
        'fee_kind' => 'inventado',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'signatory_name', 'fee_value', 'warranty_days', 'jurisdiction', 'fee_kind',
        ]);
});

it('informa qué falta para poder emitir un contrato', function (): void {
    config()->set('contracts.signatory.name', null);
    config()->set('contracts.signatory.title', null);

    actingAsAdmin();

    $this->getJson('/api/v1/admin/contract-settings')
        ->assertOk()
        ->assertJsonPath('data.is_ready_to_issue', false)
        ->assertJsonPath('data.missing_to_issue', ['signatory_name', 'signatory_title']);
});

it('sube la firma del apoderado al disco privado y sirve su vista previa', function (): void {
    actingAsAdmin();

    $this->postJson('/api/v1/admin/contract-settings/signature', [
        'signature' => UploadedFile::fake()->image('firma.png', 600, 200),
    ])
        ->assertOk()
        ->assertJsonPath('data.has_signature', true);

    $path = ContractSetting::current()->signature_path;

    expect($path)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $path))->toBeTrue()
        // Disco privado: nada de URLs públicas para la firma de una persona.
        ->and($path)->toStartWith('contracts/settings/signature');

    $this->get('/api/v1/admin/contract-settings/signature')
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

it('reemplaza la firma anterior en lugar de acumular archivos', function (): void {
    actingAsAdmin();

    $this->postJson('/api/v1/admin/contract-settings/signature', [
        'signature' => UploadedFile::fake()->image('firma-1.png', 600, 200),
    ])->assertOk();

    $first = (string) ContractSetting::current()->signature_path;

    $this->postJson('/api/v1/admin/contract-settings/signature', [
        'signature' => UploadedFile::fake()->image('firma-2.png', 600, 200),
    ])->assertOk();

    $second = (string) ContractSetting::current()->signature_path;

    expect($second)->not->toBe($first)
        ->and(Storage::disk('local')->exists($first))->toBeFalse()
        ->and(Storage::disk('local')->exists($second))->toBeTrue();
});

it('solo acepta PNG para la firma, porque el PDF necesita transparencia', function (): void {
    actingAsAdmin();

    $this->postJson('/api/v1/admin/contract-settings/signature', [
        'signature' => UploadedFile::fake()->image('firma.jpg', 600, 200),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['signature']);
});

it('permite quitar la firma', function (): void {
    actingAsAdmin();

    $this->postJson('/api/v1/admin/contract-settings/signature', [
        'signature' => UploadedFile::fake()->image('firma.png', 600, 200),
    ])->assertOk();

    $path = (string) ContractSetting::current()->signature_path;

    $this->deleteJson('/api/v1/admin/contract-settings/signature')
        ->assertOk()
        ->assertJsonPath('data.has_signature', false);

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(ContractSetting::current()->signature_path)->toBeNull();
});

it('devuelve 404 en la vista previa cuando no hay firma cargada', function (): void {
    actingAsAdmin();

    $this->get('/api/v1/admin/contract-settings/signature')->assertStatus(404);
});

it('mantiene fuera a quien no es admin', function (): void {
    foreach ([UserRole::Recruiter, UserRole::CompanyUser, UserRole::Candidate] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/contract-settings')->assertStatus(403);
        $this->putJson('/api/v1/admin/contract-settings', contractSettingsPayload())->assertStatus(403);
        $this->deleteJson('/api/v1/admin/contract-settings/signature')->assertStatus(403);
    }
});

/*
 * El punto central de todo el diseño: cambiar la configuración NO puede tocar un
 * contrato ya firmado.
 */
it('un cambio de condiciones no altera los contratos ya firmados', function (): void {
    Http::fake(['api.cincel.digital/*' => Http::response('asn1', 200)]);
    config()->set('services.cincel.jwt', 'test-jwt');
    config()->set('services.cincel.retry_delay_ms', 0);

    // Condiciones vigentes al momento de firmar.
    $admin = actingAsAdmin();
    $this->putJson('/api/v1/admin/contract-settings', contractSettingsPayload([
        'fee_value' => 12,
        'warranty_days' => 90,
    ]))->assertOk();

    // La empresa firma con esas condiciones.
    $company = Company::factory()->create();
    $signer = User::factory()->create();
    $signer->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $signer->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);
    Sanctum::actingAs($signer);

    $this->postJson('/api/v1/me/company/contract', [
        'signature' => UploadedFile::fake()->image('firma.png', 600, 200),
        'identity' => UploadedFile::fake()->image('ine.jpg', 800, 500),
        'selfie' => UploadedFile::fake()->image('selfie.jpg', 600, 600),
        'signer_title' => 'Directora General',
        'accept_privacy' => '1',
        'accept_terms' => '1',
    ])->assertCreated();

    $contract = CompanyContract::acrossCompanies()->firstOrFail();
    $pdfBefore = Storage::disk('local')->get($contract->pdf_path);

    // HUMAE renegocia sus condiciones después.
    Sanctum::actingAs($admin);
    $this->putJson('/api/v1/admin/contract-settings', contractSettingsPayload([
        'fee_value' => 25,
        'warranty_days' => 15,
        'jurisdiction' => 'la ciudad de Monterrey, Nuevo León',
    ]))->assertOk();

    $contract->refresh();

    // El snapshot conserva lo que la empresa aceptó.
    expect((float) $contract->terms['fee_value'])->toBe(12.0)
        ->and((int) $contract->terms['warranty_days'])->toBe(90)
        ->and($contract->terms['jurisdiction'])->toBe('la Ciudad de México, Estados Unidos Mexicanos')
        // Y el PDF almacenado no se toca: es el documento que se selló.
        ->and(Storage::disk('local')->get($contract->pdf_path))->toBe($pdfBefore);

    // Las condiciones nuevas sí aplican al siguiente contrato.
    expect(app(CompanyContractService::class)->currentTerms()['fee_value'])->toBe(25.0);
});

it('la firma cargada después no reemplaza la del contrato ya emitido', function (): void {
    actingAsAdmin();

    // Contrato emitido cuando no había firma de HUMAE cargada.
    $contract = CompanyContract::factory()->create([
        'terms' => array_merge(
            CompanyContract::factory()->make()->terms,
            ['signature_path' => null],
        ),
    ]);

    $this->postJson('/api/v1/admin/contract-settings/signature', [
        'signature' => UploadedFile::fake()->image('firma.png', 600, 200),
    ])->assertOk();

    $contract->refresh();

    // Su snapshot sigue sin firma: reimprimirlo no debe inventar una que no
    // existía cuando se firmó.
    expect($contract->terms['signature_path'])->toBeNull();
});
