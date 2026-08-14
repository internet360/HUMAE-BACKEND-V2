<?php

declare(strict_types=1);

use App\Enums\AttemptStatus;
use App\Enums\CandidateState;
use App\Enums\CompanyMemberRole;
use App\Enums\MembershipStatus;
use App\Enums\SkillLevel;
use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricTest;
use App\Models\SalaryCurrency;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Vista previa anónima del talento para la empresa cliente.
 *
 * Lo que se prueba aquí no es sólo que el endpoint responda: es que NO diga
 * quién es cada persona. La mitad de los casos son sobre lo que falta en la
 * respuesta, porque una fuga de identidad en esta superficie deja al empleador
 * contactar al candidato por fuera y a HUMAE sin comisión.
 *
 * El directorio interno sigue cerrado a la empresa; eso lo vigila
 * `tests/Feature/Security/CompanyUserDirectoryAccessTest.php`.
 */
const ANONYMOUS_DIRECTORY_URL = '/api/v1/me/company/directory/candidates';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function anonymousDirectoryPlan(): MembershipPlan
{
    $currency = SalaryCurrency::where('code', 'MXN')->first()
        ?? SalaryCurrency::factory()->create(['code' => 'MXN']);

    return MembershipPlan::where('code', 'candidate_6m')->first()
        ?? MembershipPlan::factory()->create([
            'code' => 'candidate_6m',
            'salary_currency_id' => $currency->id,
        ]);
}

function anonymousDirectoryCandidate(array $profile = [], bool $withMembership = true): CandidateProfile
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);

    if ($withMembership) {
        Membership::factory()->create([
            'user_id' => $user->id,
            'membership_plan_id' => anonymousDirectoryPlan()->id,
            'status' => MembershipStatus::Active,
            'started_at' => now()->subDay(),
            'expires_at' => now()->addDays(100),
        ]);
    }

    return CandidateProfile::factory()->create(array_merge([
        'user_id' => $user->id,
        'state' => CandidateState::Activo->value,
    ], $profile));
}

function actAsCompanyUser(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);

    $company = Company::factory()->create();
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => CompanyMemberRole::Owner->value,
        'is_primary_contact' => true,
        'accepted_at' => now(),
    ]);

    Sanctum::actingAs($user);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Autorización
|--------------------------------------------------------------------------
*/

it('locks a guest out', function (): void {
    $this->getJson(ANONYMOUS_DIRECTORY_URL)->assertUnauthorized();
});

it('lets a company user browse the anonymous preview', function (): void {
    anonymousDirectoryCandidate();
    actAsCompanyUser();

    $this->getJson(ANONYMOUS_DIRECTORY_URL)
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('locks a candidate out', function (): void {
    anonymousDirectoryCandidate();

    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $this->getJson(ANONYMOUS_DIRECTORY_URL)->assertForbidden();
});

it('locks a recruiter out — staff has the full directory instead', function (): void {
    anonymousDirectoryCandidate();

    $user = User::factory()->create();
    $user->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($user);

    $this->getJson(ANONYMOUS_DIRECTORY_URL)->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Anonimato — el corazón de la superficie
|--------------------------------------------------------------------------
*/

it('never returns identity, contact data or database ids', function (): void {
    anonymousDirectoryCandidate([
        'first_name' => 'Mariana',
        'last_name' => 'Gutierrez',
        'contact_email' => 'mariana@example.com',
        'contact_phone' => '5511223344',
        'headline' => 'Contadora Senior',
    ]);
    actAsCompanyUser();

    $response = $this->getJson(ANONYMOUS_DIRECTORY_URL)->assertOk();

    $card = $response->json('data.0');

    foreach (['id', 'user_id', 'first_name', 'last_name', 'avatar_url', 'contact_email', 'contact_phone', 'summary', 'curp', 'rfc', 'state'] as $forbidden) {
        expect($card)->not->toHaveKey($forbidden);
    }

    // Y por si algún campo futuro los arrastra dentro de otra estructura.
    $body = $response->getContent();
    expect($body)->not->toContain('Mariana')
        ->and($body)->not->toContain('Gutierrez')
        ->and($body)->not->toContain('mariana@example.com')
        ->and($body)->not->toContain('5511223344');
});

it('addresses each candidate by an opaque reference, not by id', function (): void {
    $candidate = anonymousDirectoryCandidate();
    actAsCompanyUser();

    $card = $this->getJson(ANONYMOUS_DIRECTORY_URL)->assertOk()->json('data.0');

    expect($card['reference'])->toBe($candidate->public_reference)
        ->and($card['reference'])->not->toBe((string) $candidate->id)
        ->and($card['display_code'])->toHaveLength(6);
});

it('assigns a public reference to every new profile', function (): void {
    $candidate = anonymousDirectoryCandidate();

    expect($candidate->public_reference)->not->toBeNull()
        ->and($candidate->public_reference)->toMatch('/^[0-9a-f-]{36}$/');
});

it('exposes whether the candidate has psychometrics, never the result', function (): void {
    $withTest = anonymousDirectoryCandidate(['headline' => 'Con prueba']);
    anonymousDirectoryCandidate(['headline' => 'Sin prueba']);

    $test = PsychometricTest::factory()->create();
    PsychometricAttempt::factory()->create([
        'candidate_profile_id' => $withTest->id,
        'psychometric_test_id' => $test->id,
        'status' => AttemptStatus::Completed->value,
    ]);

    actAsCompanyUser();

    $cards = collect($this->getJson(ANONYMOUS_DIRECTORY_URL.'?per_page=50')->assertOk()->json('data'))
        ->keyBy('headline');

    expect($cards['Con prueba']['has_psychometrics'])->toBeTrue()
        ->and($cards['Sin prueba']['has_psychometrics'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| El buscador no puede volverse un oráculo de nombres
|--------------------------------------------------------------------------
*/

it('does not let free text search confirm that a person is in the base', function (): void {
    anonymousDirectoryCandidate([
        'first_name' => 'Mariana',
        'last_name' => 'Gutierrez',
        'headline' => 'Contadora Senior',
    ]);
    actAsCompanyUser();

    // Escribir el nombre y leer el conteo confirmaría que existe, aunque la
    // respuesta no muestre nombres. Ese es el agujero que este caso cierra.
    $this->getJson(ANONYMOUS_DIRECTORY_URL.'?q=Gutierrez')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson(ANONYMOUS_DIRECTORY_URL.'?q=Mariana')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('still searches by professional headline', function (): void {
    anonymousDirectoryCandidate(['headline' => 'Contadora Senior']);
    anonymousDirectoryCandidate(['headline' => 'Desarrollador Backend']);
    actAsCompanyUser();

    $this->getJson(ANONYMOUS_DIRECTORY_URL.'?q=Contadora')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.headline', 'Contadora Senior');
});

/*
|--------------------------------------------------------------------------
| Filtros forzados del lado del servidor
|--------------------------------------------------------------------------
*/

it('hides candidates without an active membership', function (): void {
    anonymousDirectoryCandidate(['headline' => 'Vigente']);
    anonymousDirectoryCandidate(['headline' => 'Sin membresia'], withMembership: false);
    actAsCompanyUser();

    $this->getJson(ANONYMOUS_DIRECTORY_URL)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.headline', 'Vigente');
});

it('ignores an attempt to switch the membership filter off', function (): void {
    anonymousDirectoryCandidate(['headline' => 'Vigente']);
    anonymousDirectoryCandidate(['headline' => 'Sin membresia'], withMembership: false);
    actAsCompanyUser();

    $this->getJson(ANONYMOUS_DIRECTORY_URL.'?has_active_membership=0')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('hides candidates in internal states', function (): void {
    anonymousDirectoryCandidate(['headline' => 'Activo']);
    anonymousDirectoryCandidate([
        'headline' => 'Inactivo',
        'state' => CandidateState::Inactivo->value,
    ]);
    actAsCompanyUser();

    $this->getJson(ANONYMOUS_DIRECTORY_URL)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.headline', 'Activo');
});

it('ignores an attempt to query an internal state directly', function (): void {
    anonymousDirectoryCandidate(['headline' => 'Activo']);
    anonymousDirectoryCandidate([
        'headline' => 'Inactivo',
        'state' => CandidateState::Inactivo->value,
    ]);
    actAsCompanyUser();

    // Si `state` se colara al servicio, la empresa leería a quién descartó HUMAE.
    $this->getJson(ANONYMOUS_DIRECTORY_URL.'?state=inactivo')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.headline', 'Activo');
});

/*
|--------------------------------------------------------------------------
| Filtros útiles y validación
|--------------------------------------------------------------------------
*/

it('filters by skill', function (): void {
    $sap = Skill::factory()->create(['name' => 'SAP']);
    $wanted = anonymousDirectoryCandidate(['headline' => 'Con SAP']);
    $wanted->skills()->attach($sap->id, ['level' => SkillLevel::Avanzado->value]);
    anonymousDirectoryCandidate(['headline' => 'Sin SAP']);

    actAsCompanyUser();

    $this->getJson(ANONYMOUS_DIRECTORY_URL.'?skills[]='.$sap->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.headline', 'Con SAP');
});

it('filters by years of experience', function (): void {
    anonymousDirectoryCandidate(['headline' => 'Junior', 'years_of_experience' => 2]);
    anonymousDirectoryCandidate(['headline' => 'Senior', 'years_of_experience' => 9]);
    actAsCompanyUser();

    $this->getJson(ANONYMOUS_DIRECTORY_URL.'?years_exp_min=5')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.headline', 'Senior');
});

it('rejects an invalid filter', function (): void {
    actAsCompanyUser();

    $this->getJson(ANONYMOUS_DIRECTORY_URL.'?per_page=500')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');

    $this->getJson(ANONYMOUS_DIRECTORY_URL.'?modalities[]=telepatia')
        ->assertStatus(422)
        ->assertJsonValidationErrors('modalities.0');
});

it('returns pagination metadata', function (): void {
    anonymousDirectoryCandidate();
    anonymousDirectoryCandidate();
    actAsCompanyUser();

    $this->getJson(ANONYMOUS_DIRECTORY_URL.'?per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonPath('meta.pagination.per_page', 1)
        ->assertJsonPath('meta.pagination.last_page', 2);
});

/*
|--------------------------------------------------------------------------
| La foto
|--------------------------------------------------------------------------
|
| Decisión de producto: la empresa ve la cara antes de que HUMAE confirme la
| entrevista. Lo que sigue reservado es el nombre y el contacto, y estas pruebas
| fijan que la foto no arrastre consigo nada más.
|
*/

it('serves the photo through a signed link, never the public avatar url', function (): void {
    Storage::fake('public');
    actAsCompanyUser();

    $candidate = anonymousDirectoryCandidate();
    $candidate->user->forceFill([
        'avatar_path' => 'avatars/'.$candidate->user_id.'/foto.webp',
        'avatar_url' => 'http://localhost/storage/avatars/'.$candidate->user_id.'/foto.webp',
    ])->save();
    Storage::disk('public')->put($candidate->user->avatar_path, 'imagen');

    $response = $this->getJson('/api/v1/me/company/directory/candidates')->assertOk();
    $photoUrl = $response->json('data.0.photo_url');

    expect($photoUrl)->toBeString()
        ->and($photoUrl)->toContain('signature=')
        // Ni la ruta pública ni el segmento que lleva el id adentro: el
        // `avatar_url` de hoy expone los dos.
        ->and($photoUrl)->not->toContain('/storage/')
        ->and($photoUrl)->not->toContain('avatars/')
        ->and($photoUrl)->toContain($candidate->public_reference);

    // Y el cuerpo entero sigue sin filtrar la url pública.
    expect($response->getContent())->not->toContain('avatar_url');
});

it('opens the signed photo without a session, because an img tag has none', function (): void {
    Storage::fake('public');
    actAsCompanyUser();

    $candidate = anonymousDirectoryCandidate();
    $candidate->user->forceFill(['avatar_path' => 'avatars/x/foto.webp'])->save();
    Storage::disk('public')->put('avatars/x/foto.webp', 'imagen');

    $photoUrl = $this->getJson('/api/v1/me/company/directory/candidates')
        ->json('data.0.photo_url');

    app('auth')->forgetGuards();

    $this->get($photoUrl)
        ->assertOk()
        ->assertHeader('cache-control', 'max-age=300, private');
});

it('refuses a tampered or expired link', function (): void {
    Storage::fake('public');
    actAsCompanyUser();

    $candidate = anonymousDirectoryCandidate();
    $candidate->user->forceFill(['avatar_path' => 'avatars/x/foto.webp'])->save();
    Storage::disk('public')->put('avatars/x/foto.webp', 'imagen');

    $photoUrl = $this->getJson('/api/v1/me/company/directory/candidates')
        ->json('data.0.photo_url');

    // Sin firma no entra: es la única credencial de esta ruta.
    $this->get(strtok($photoUrl, '?'))->assertStatus(403);

    // Y caduca sola, que es lo que la distingue del avatar público.
    $this->travel(31)->minutes();
    $this->get($photoUrl)->assertStatus(403);
});

it('stops serving a signed link once the candidate leaves the pool', function (): void {
    Storage::fake('public');
    actAsCompanyUser();

    $candidate = anonymousDirectoryCandidate();
    $candidate->user->forceFill(['avatar_path' => 'avatars/x/foto.webp'])->save();
    Storage::disk('public')->put('avatars/x/foto.webp', 'imagen');

    $photoUrl = $this->getJson('/api/v1/me/company/directory/candidates')
        ->json('data.0.photo_url');

    $this->get($photoUrl)->assertOk();

    // Vence la membresía con el enlace todavía firmado y vigente.
    $candidate->user->memberships()->update(['status' => MembershipStatus::Expired->value]);

    // La firma sigue siendo válida y aun así no se sirve: un enlace no debe
    // sobrevivir a la salida de la persona del padrón.
    $this->get($photoUrl)->assertStatus(404);
});

it('returns no photo url for a candidate who never uploaded one', function (): void {
    actAsCompanyUser();
    anonymousDirectoryCandidate();

    $this->getJson('/api/v1/me/company/directory/candidates')
        ->assertOk()
        ->assertJsonPath('data.0.photo_url', null);
});
