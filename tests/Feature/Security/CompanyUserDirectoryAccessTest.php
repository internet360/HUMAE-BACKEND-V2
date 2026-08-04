<?php

declare(strict_types=1);

use App\Enums\CandidateState;
use App\Enums\CompanyMemberRole;
use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\DirectoryFavorite;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Probe: what can a company_user reach on the PRIVATE candidate directory?
 *
 * ARCHITECTURE.md §5.5 scopes the directory to recruiter/admin and §6 is
 * explicit per row: "Ver expediente completo de candidato: Empresa ❌",
 * "Descargar CV de cualquier candidato: Empresa ❌", "Marcar favoritos:
 * Empresa ❌".
 *
 * The one deliberate deviation kept alive here is the compact listing, which
 * the shipped company panel (/me/empresa/directorio) uses to point at a
 * candidate and request a vacancy for them.
 *
 * Note there is no relationship whatsoever between this company and this
 * candidate: the directory gate was purely role-based, so any company reached
 * any candidate.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->companyUser = User::factory()->create();
    $this->companyUser->assignRole(UserRole::CompanyUser->value);

    $company = Company::factory()->create();
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $this->companyUser->id,
        'role' => CompanyMemberRole::Owner->value,
        'is_primary_contact' => true,
        'accepted_at' => now(),
    ]);

    $candidateUser = User::factory()->create();
    $candidateUser->assignRole(UserRole::Candidate->value);

    $currency = SalaryCurrency::where('code', 'MXN')->first()
        ?? SalaryCurrency::factory()->create(['code' => 'MXN']);
    $plan = MembershipPlan::where('code', 'candidate_6m')->first()
        ?? MembershipPlan::factory()->create([
            'code' => 'candidate_6m',
            'salary_currency_id' => $currency->id,
        ]);
    Membership::factory()->create([
        'user_id' => $candidateUser->id,
        'membership_plan_id' => $plan->id,
        'status' => MembershipStatus::Active,
        'started_at' => now()->subDay(),
        'expires_at' => now()->addDays(100),
    ]);

    $this->candidate = CandidateProfile::factory()->create([
        'user_id' => $candidateUser->id,
        'state' => CandidateState::Activo->value,
        'curp' => 'PEPJ800101HDFRRN09',
        'rfc' => 'PEPJ800101AB1',
        'contact_phone' => '5555555555',
    ]);
});

it('still lets a company browse the compact directory listing', function (): void {
    Sanctum::actingAs($this->companyUser);

    $this->getJson('/api/v1/directory/candidates')->assertOk();
});

it('does not expose the full candidate record to a company', function (): void {
    Sanctum::actingAs($this->companyUser);

    // The expediente carries CURP, RFC, home address, contact phone, email,
    // references and document metadata.
    $this->getJson("/api/v1/directory/candidates/{$this->candidate->id}")
        ->assertForbidden();
});

it('does not let a company download an arbitrary candidate CV', function (): void {
    Sanctum::actingAs($this->companyUser);

    $this->get("/api/v1/directory/candidates/{$this->candidate->id}/cv.pdf")
        ->assertForbidden();
});

it('does not let a company download a private candidate document', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('candidate-documents/probe.pdf', '%PDF-probe');

    $document = CandidateDocument::factory()->create([
        'candidate_profile_id' => $this->candidate->id,
        'file_public_id' => 'candidate-documents/probe.pdf',
        'is_internal' => false,
    ]);

    Sanctum::actingAs($this->companyUser);

    $this->get("/api/v1/directory/candidates/{$this->candidate->id}/documents/{$document->id}/download")
        ->assertForbidden();

    // Same file, same route: a recruiter gets it. The gate was the only
    // difference — the document was reachable, not merely missing.
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $this->get("/api/v1/directory/candidates/{$this->candidate->id}/documents/{$document->id}/download")
        ->assertOk();
});

it('does not let a company bookmark candidates in the recruiter favorites table', function (): void {
    Sanctum::actingAs($this->companyUser);

    $this->postJson("/api/v1/directory/candidates/{$this->candidate->id}/favorite")
        ->assertForbidden();

    expect(DirectoryFavorite::where('recruiter_id', $this->companyUser->id)->exists())
        ->toBeFalse();
});

it('keeps the whole directory open to a recruiter', function (): void {
    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $this->getJson('/api/v1/directory/candidates')->assertOk();
    $this->getJson("/api/v1/directory/candidates/{$this->candidate->id}")->assertOk();
    $this->get("/api/v1/directory/candidates/{$this->candidate->id}/cv.pdf")->assertOk();
    $this->postJson("/api/v1/directory/candidates/{$this->candidate->id}/favorite")->assertCreated();
});

it('keeps the whole directory open to an admin', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/directory/candidates')->assertOk();
    $this->getJson("/api/v1/directory/candidates/{$this->candidate->id}")->assertOk();
    $this->get("/api/v1/directory/candidates/{$this->candidate->id}/cv.pdf")->assertOk();
});

it('locks a candidate out of the directory', function (): void {
    $intruder = User::factory()->create();
    $intruder->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($intruder);

    $this->getJson('/api/v1/directory/candidates')->assertForbidden();
    $this->getJson("/api/v1/directory/candidates/{$this->candidate->id}")->assertForbidden();
    $this->get("/api/v1/directory/candidates/{$this->candidate->id}/cv.pdf")->assertForbidden();
    $this->postJson("/api/v1/directory/candidates/{$this->candidate->id}/favorite")->assertForbidden();
});
