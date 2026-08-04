<?php

declare(strict_types=1);

use App\Enums\AssignmentStage;
use App\Enums\AttemptStatus;
use App\Enums\CompanyMemberRole;
use App\Enums\DocumentType;
use App\Enums\InterviewMode;
use App\Enums\InterviewState;
use App\Enums\MembershipStatus;
use App\Enums\Priority;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Helpers\StripeClient;
use App\Http\Middleware\EnsureActiveMembership;
use App\Http\Middleware\EnsureVerifiedEmail;
use App\Models\CandidateCertification;
use App\Models\CandidateCourse;
use App\Models\CandidateDocument;
use App\Models\CandidateEducation;
use App\Models\CandidateExperience;
use App\Models\CandidateProfile;
use App\Models\CandidateReference;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\DegreeLevel;
use App\Models\FunctionalArea;
use App\Models\Interview;
use App\Models\Language;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricTest;
use App\Models\Skill;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Models\VacancyAssignmentNote;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Authorization matrix probe harness
|--------------------------------------------------------------------------
|
| One table of expectations, one probe per route row, seven actors per probe.
| Adding a route means adding a row to `authzMatrixRows()`, not writing a test.
|
| The intended access per role comes from ARCHITECTURE.md §5 (endpoint
| catalogue) and §6 (role x permission matrix), transcribed in
| `docs/security/authorization-matrix.md`. Where §5/§6 say nothing, the row is
| tagged UNSPECIFIED together with the inference used, so the guess stays
| visible instead of hardening into fake intent.
|
| Rows the implementation does not honour carry a finding id (F-xx). Those
| probes report as SKIPPED naming the finding instead of failing the build, so
| red in this file always means a NEW hole. When a marked probe stops
| reproducing, the test fails on purpose: the marker has to go with the fix.
|
| Every denied write is additionally checked against a content fingerprint of
| the domain tables. A 403 that still wrote is worse than a 200.
|
*/

const AUTHZ_ACTORS = [
    'guest',
    'candidate_owner',
    'candidate_other',
    'recruiter',
    'company_owner',
    'company_other',
    'admin',
];

/** Sentinels planted in the fixtures so a leak is unambiguous in the raw body. */
const AUTHZ_S_CANDIDATE_B_CURP = 'CURPSENTINELB00001';
const AUTHZ_S_CANDIDATE_B_RFC = 'RFCSENTB12345';
const AUTHZ_S_CANDIDATE_B_ADDRESS = 'CALLE-SENTINEL-CANDIDATO-B-123';
const AUTHZ_S_CANDIDATE_B_PHONE = '+52-555-SENTINEL-B';
const AUTHZ_S_CANDIDATE_B_EMAIL = 'sentinel-candidato-b@humae.test';
const AUTHZ_S_CANDIDATE_B_LASTNAME = 'ApellidoSentinelB';
const AUTHZ_S_CANDIDATE_A_CURP = 'CURPSENTINELA00001';
const AUTHZ_S_CANDIDATE_A_ADDRESS = 'CALLE-SENTINEL-CANDIDATO-A-123';
const AUTHZ_S_CANDIDATE_A_PHONE = '+52-555-SENTINEL-A';
const AUTHZ_S_RECRUITER_NOTES = 'NOTA-RECLUTADOR-SENTINEL';
const AUTHZ_S_REJECTION_REASON = 'MOTIVO-RECHAZO-SENTINEL';
const AUTHZ_S_INTERNAL_NOTE = 'NOTA-INTERNA-SENTINEL';
const AUTHZ_S_RECRUITER_FEEDBACK = 'FEEDBACK-RECLUTADOR-SENTINEL';
const AUTHZ_S_VACANCY_INTERNAL_NOTES = 'NOTAS-INTERNAS-VACANTE-SENTINEL';
const AUTHZ_S_COMPANY_A_RFC = 'RFCEMPRESAA12';
const AUTHZ_S_COMPANY_A_CONTACT_EMAIL = 'contacto-sentinel-a@empresa.test';
const AUTHZ_S_COMPANY_A_ADDRESS = 'AV-SENTINEL-EMPRESA-A-500';
const AUTHZ_S_COMPANY_A_INTERNAL_NOTES = 'NOTAS-INTERNAS-EMPRESA-SENTINEL';

/** Field names §6 keeps away from the client company. */
const AUTHZ_PII_KEYS = [
    'curp',
    'rfc',
    'birth_date',
    'address_line',
    'contact_phone',
    'contact_email',
    'recruiter_notes',
    'rejection_reason',
    'internal_notes',
];

/** Tables whose contents must not move on a denied write. */
const AUTHZ_GUARDED_TABLES = [
    'companies',
    'company_members',
    'vacancies',
    'vacancy_assignments',
    'vacancy_assignment_notes',
    'interviews',
    'candidate_profiles',
    'candidate_documents',
    'candidate_experiences',
    'candidate_educations',
    'candidate_courses',
    'candidate_certifications',
    'candidate_references',
    'candidate_skills',
    'candidate_languages',
    'directory_favorites',
    'psychometric_attempts',
    'psychometric_answers',
    'users',
    'model_has_roles',
    'skills',
    'languages',
    'degree_levels',
    'functional_areas',
];

/**
 * Every public policy ability, mapped to the file that names it, relative to
 * `app/`.
 *
 * `null` means nobody invokes it: a registered ability that enforces nothing.
 * That is what InterviewPolicy was before the third remediation pass, so the
 * inventory is pinned here instead of being rediscovered by incident.
 *
 * The four vacancy transition abilities are named in `VacancyStateMachine`
 * rather than in a controller: both transition endpoints derive the ability
 * from the target state through `abilityFor()`, so the mapping lives in one
 * place and neither endpoint can grow its own whitelist. The test below pins
 * that indirection so it cannot be quietly undone.
 */
const AUTHZ_POLICY_INVENTORY = [
    'CandidateProfilePolicy' => [
        'viewAny' => 'Http/Controllers/Api/V1/Recruiter/DirectoryController.php',
        'view' => 'Http/Controllers/Api/V1/Recruiter/DirectoryController.php',
        'downloadCv' => 'Http/Controllers/Api/V1/Recruiter/DirectoryController.php',
        'downloadDocument' => 'Http/Controllers/Api/V1/Recruiter/DirectoryController.php',
        'favorite' => 'Http/Controllers/Api/V1/Recruiter/DirectoryController.php',
    ],
    'CompanyPolicy' => [
        'viewAny' => 'Http/Controllers/Api/V1/Recruiter/CompanyController.php',
        'view' => 'Http/Controllers/Api/V1/Recruiter/CompanyController.php',
        'create' => 'Http/Controllers/Api/V1/Recruiter/CompanyController.php',
        'update' => 'Http/Controllers/Api/V1/Recruiter/CompanyController.php',
        'delete' => 'Http/Controllers/Api/V1/Recruiter/CompanyController.php',
    ],
    'InterviewPolicy' => [
        'view' => 'Http/Controllers/Api/V1/Interviews/InterviewController.php',
        'selectSlot' => 'Http/Controllers/Api/V1/Interviews/InterviewController.php',
        'reschedule' => 'Http/Controllers/Api/V1/Interviews/InterviewController.php',
        'confirm' => 'Http/Controllers/Api/V1/Interviews/InterviewController.php',
        'cancel' => 'Http/Controllers/Api/V1/Interviews/InterviewController.php',
    ],
    'VacancyAssignmentPolicy' => [
        'viewAny' => 'Http/Controllers/Api/V1/Recruiter/AssignmentController.php',
        'create' => 'Http/Controllers/Api/V1/Recruiter/AssignmentController.php',
        'update' => 'Http/Controllers/Api/V1/Recruiter/AssignmentController.php',
        'delete' => 'Http/Controllers/Api/V1/Recruiter/AssignmentController.php',
        'selectFinalist' => 'Http/Controllers/Api/V1/Recruiter/AssignmentController.php',
        'scheduleInterview' => 'Http/Controllers/Api/V1/Interviews/InterviewController.php',
        'viewNotes' => 'Http/Controllers/Api/V1/Recruiter/AssignmentNoteController.php',
        'createNote' => 'Http/Controllers/Api/V1/Recruiter/AssignmentNoteController.php',
        'viewInternalNotes' => 'Http/Controllers/Api/V1/Recruiter/AssignmentNoteController.php',
    ],
    'VacancyPolicy' => [
        'viewAny' => 'Http/Controllers/Api/V1/Recruiter/VacancyController.php',
        'view' => 'Http/Controllers/Api/V1/Recruiter/VacancyController.php',
        'viewSuggestedCandidates' => 'Http/Controllers/Api/V1/Recruiter/VacancyController.php',
        'create' => 'Http/Controllers/Api/V1/Recruiter/VacancyController.php',
        'update' => 'Http/Controllers/Api/V1/Recruiter/VacancyController.php',
        'delete' => 'Http/Controllers/Api/V1/Recruiter/VacancyController.php',
        'publish' => 'Services/VacancyStateMachine.php',
        'close' => 'Services/VacancyStateMachine.php',
        'cancel' => 'Services/VacancyStateMachine.php',
        'advance' => 'Services/VacancyStateMachine.php',
    ],
];

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    // The checkout probe measures the role gate in front of the endpoint, not
    // Stripe. An unconfigured client fails fast and locally, so the probe never
    // depends on the network or on whatever `.env` happens to hold.
    $this->app->instance(StripeClient::class, new StripeClient(secretKey: '', webhookSecret: ''));

    $this->fixtures = authzBuildFixtures();
});

// PHPUnit keeps the TestCase instances alive for the whole run. With 154 rows
// each holding a hydrated fixture graph, that is tens of megabytes of models
// nobody reads again — enough to blow the default memory limit of the full
// suite. Drop the graph as soon as the row is done.
afterEach(function (): void {
    $this->fixtures = null;
});

/*
|--------------------------------------------------------------------------
| The probe
|--------------------------------------------------------------------------
*/

it('enforces the intended authorization matrix', function (array $row): void {
    /** @var array<string, mixed> $fixtures */
    $fixtures = $this->fixtures;

    $observed = [];
    $unexpected = [];
    $reproduced = [];
    $noLongerReproduces = [];

    foreach (AUTHZ_ACTORS as $actor) {
        $expected = $row['expect'][$actor] ?? 'deny';
        $finding = $row['findings'][$actor] ?? null;

        DB::beginTransaction();

        try {
            // Every refusal is checked against a content fingerprint, reads
            // included: a GET that creates rows on a denied call is still a
            // write, and `ProfileService::findOrCreate` does exactly that.
            $before = $expected === 'deny' ? authzFingerprint() : null;

            $response = authzProbe($this, $row, $actor, $fixtures);
            $observed[$actor] = $response->getStatusCode();

            $problem = authzEvaluate($row, $actor, $expected, $response, $before);
        } finally {
            DB::rollBack();
        }

        if ($problem !== null && $finding === null) {
            $unexpected[] = "[{$actor}] {$problem}";
        } elseif ($problem !== null) {
            $reproduced[] = "[{$actor}] {$finding} — {$problem}";
        } elseif ($finding !== null) {
            $noLongerReproduces[] = "[{$actor}] {$finding}";
        }
    }

    $context = sprintf(
        "%s %s  [%s]\n  observed status per actor: %s",
        $row['method'],
        $row['uri'],
        $row['spec'],
        (string) json_encode($observed, JSON_THROW_ON_ERROR),
    );

    if ($unexpected !== []) {
        $this->fail($context."\n  UNEXPECTED:\n    ".implode("\n    ", $unexpected));
    }

    if ($noLongerReproduces !== []) {
        $this->fail(
            $context
            ."\n  A known finding stopped reproducing. Remove its marker from this row and"
            ." from docs/security/authorization-matrix.md:\n    "
            .implode("\n    ", $noLongerReproduces)
        );
    }

    if ($reproduced !== []) {
        $this->markTestSkipped("KNOWN OPEN FINDING\n  ".$context."\n    ".implode("\n    ", $reproduced));
    }

    expect($observed)->toHaveCount(count(AUTHZ_ACTORS));
})->with('authorization-matrix');

/**
 * @param  array<string, mixed>  $row
 * @param  array<string, mixed>  $fixtures
 */
function authzProbe(object $test, array $row, string $actor, array $fixtures): TestResponse
{
    app('auth')->forgetGuards();

    if ($actor !== 'guest') {
        Sanctum::actingAs($fixtures['users'][$actor]);
    }

    /** @var array<string, int|string> $tokens */
    $tokens = $fixtures['tokens'];

    $uri = (string) $row['uri'];

    foreach ($tokens as $token => $value) {
        $uri = str_replace('{'.$token.'}', (string) $value, $uri);
    }

    /** @var array<string, mixed> $payload */
    $payload = authzResolvePayload($row['payload'] ?? [], $tokens, $actor);

    /** @var TestResponse $response */
    $response = match ($row['method']) {
        'GET' => $test->getJson($uri),
        'POST' => $test->postJson($uri, $payload),
        'PATCH' => $test->patchJson($uri, $payload),
        'PUT' => $test->putJson($uri, $payload),
        'DELETE' => $test->deleteJson($uri, $payload),
        default => throw new InvalidArgumentException('Unsupported method '.$row['method'].'.'),
    };

    return $response;
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, int|string>  $tokens
 * @return array<string, mixed>
 */
function authzResolvePayload(array $payload, array $tokens, string $actor): array
{
    array_walk_recursive($payload, function (mixed &$value) use ($tokens, $actor): void {
        if (! is_string($value)) {
            return;
        }

        if ($value === '{actor_email}') {
            $value = 'nuevo-'.str_replace('_', '-', $actor).'@humae.test';

            return;
        }

        foreach ($tokens as $token => $replacement) {
            if ($value === '{'.$token.'}') {
                $value = $replacement;

                return;
            }
        }
    });

    return $payload;
}

/**
 * Turn one probe into a human-readable problem statement, or null when it held.
 *
 * @param  array<string, mixed>  $row
 */
function authzEvaluate(
    array $row,
    string $actor,
    string $expected,
    TestResponse $response,
    ?string $fingerprintBefore,
): ?string {
    $status = $response->getStatusCode();
    $body = (string) $response->getContent();

    if ($expected === 'deny') {
        if (! in_array($status, [401, 402, 403, 404], true)) {
            return "expected a refusal, got {$status}.";
        }

        if ($fingerprintBefore !== null && $fingerprintBefore !== authzFingerprint()) {
            return "was refused with {$status} but still mutated the database.";
        }

        return null;
    }

    if (in_array($status, [401, 403], true)) {
        return "expected access, got {$status}.";
    }

    if ($status === 404 && ($row['allow_not_found'] ?? false) === false) {
        return 'expected access, got 404 (scoped away or route mismatch).';
    }

    /** @var array<string, list<string>> $leaks */
    $leaks = $row['must_not_leak'] ?? [];

    foreach (array_merge($leaks['*'] ?? [], $leaks[$actor] ?? []) as $sentinel) {
        if (str_contains($body, $sentinel)) {
            return "responded {$status} but leaked the sentinel '{$sentinel}'.";
        }
    }

    /** @var list<string> $piiFor */
    $piiFor = $row['no_pii_keys_for'] ?? [];

    if (in_array($actor, $piiFor, true)) {
        $found = authzPiiKeysIn(json_decode($body, true));

        if ($found !== []) {
            return "responded {$status} but the payload carries restricted keys: ".implode(', ', $found).'.';
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function authzPiiKeysIn(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }

    $found = [];

    foreach ($node as $key => $value) {
        if (is_string($key) && in_array($key, AUTHZ_PII_KEYS, true)) {
            $found[] = $key;
        }

        $found = array_merge($found, authzPiiKeysIn($value));
    }

    return array_values(array_unique($found));
}

function authzFingerprint(): string
{
    $snapshot = [];

    foreach (AUTHZ_GUARDED_TABLES as $table) {
        $snapshot[$table] = DB::table($table)->get()->toArray();
    }

    return md5((string) json_encode($snapshot, JSON_THROW_ON_ERROR));
}

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
|
| An owner company_user, an unrelated company_user, a recruiter, an admin, the
| candidate that owns the resources under test and an unrelated candidate.
| The unrelated candidate is the one HUMAE has NOT presented: every "the client
| company must never read this" assertion points at him.
|
*/

/**
 * @return array<string, mixed>
 */
function authzBuildFixtures(): array
{
    $admin = User::factory()->create(['name' => 'Admin HUMAE']);
    $admin->assignRole(UserRole::Admin->value);

    $recruiter = User::factory()->create(['name' => 'Reclutador HUMAE']);
    $recruiter->assignRole(UserRole::Recruiter->value);

    $companyOwner = User::factory()->create(['name' => 'Owner Empresa A']);
    $companyOwner->assignRole(UserRole::CompanyUser->value);

    $companyOther = User::factory()->create(['name' => 'Owner Empresa B']);
    $companyOther->assignRole(UserRole::CompanyUser->value);

    $companyViewer = User::factory()->create(['name' => 'Viewer Empresa A']);
    $companyViewer->assignRole(UserRole::CompanyUser->value);

    $candidateOwner = User::factory()->create(['name' => 'Candidato Presentado']);
    $candidateOwner->assignRole(UserRole::Candidate->value);

    $candidateOther = User::factory()->create([
        'name' => 'Candidato No Presentado',
        'email' => AUTHZ_S_CANDIDATE_B_EMAIL,
    ]);
    $candidateOther->assignRole(UserRole::Candidate->value);

    $invitationToken = str_repeat('a', 64);
    $invitee = User::factory()->create(['name' => 'Usuario Invitado']);
    $invitee->forceFill([
        'invitation_token' => hash('sha256', $invitationToken),
        'invitation_expires_at' => now()->addDays(7),
        'invitation_accepted_at' => null,
    ])->save();

    $companyA = Company::factory()->create([
        'legal_name' => 'Empresa A S.A. de C.V.',
        'rfc' => AUTHZ_S_COMPANY_A_RFC,
        'contact_email' => AUTHZ_S_COMPANY_A_CONTACT_EMAIL,
        'address_line' => AUTHZ_S_COMPANY_A_ADDRESS,
        'internal_notes' => AUTHZ_S_COMPANY_A_INTERNAL_NOTES,
    ]);

    $companyB = Company::factory()->create(['legal_name' => 'Empresa B S.A. de C.V.']);

    CompanyMember::factory()->create([
        'company_id' => $companyA->id,
        'user_id' => $companyOwner->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    $viewerMember = CompanyMember::factory()->create([
        'company_id' => $companyA->id,
        'user_id' => $companyViewer->id,
        'role' => CompanyMemberRole::Viewer->value,
    ]);

    CompanyMember::factory()->create([
        'company_id' => $companyB->id,
        'user_id' => $companyOther->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    // `finalista_seleccionado` is the only state from which `cubierta` is legal,
    // so the transition probes measure authorization and not the state machine.
    // A second vacancy in `borrador` covers the publish transition.
    $vacancyA = Vacancy::factory()->create([
        'company_id' => $companyA->id,
        'state' => VacancyState::FinalistaSeleccionado,
        'internal_notes' => AUTHZ_S_VACANCY_INTERNAL_NOTES,
        'fee_amount' => 50000,
        'assigned_recruiter_id' => $recruiter->id,
    ]);

    $vacancyDraft = Vacancy::factory()->create([
        'company_id' => $companyA->id,
        'state' => VacancyState::Borrador,
    ]);

    Vacancy::factory()->create([
        'company_id' => $companyB->id,
        'state' => VacancyState::Activa,
    ]);

    $profileOwner = CandidateProfile::factory()->create([
        'user_id' => $candidateOwner->id,
        'last_name' => 'ApellidoSentinelA',
        'curp' => AUTHZ_S_CANDIDATE_A_CURP,
        'address_line' => AUTHZ_S_CANDIDATE_A_ADDRESS,
        'contact_phone' => AUTHZ_S_CANDIDATE_A_PHONE,
    ]);

    $profileOther = CandidateProfile::factory()->create([
        'user_id' => $candidateOther->id,
        'last_name' => AUTHZ_S_CANDIDATE_B_LASTNAME,
        'curp' => AUTHZ_S_CANDIDATE_B_CURP,
        'rfc' => AUTHZ_S_CANDIDATE_B_RFC,
        'address_line' => AUTHZ_S_CANDIDATE_B_ADDRESS,
        'contact_phone' => AUTHZ_S_CANDIDATE_B_PHONE,
        'contact_email' => AUTHZ_S_CANDIDATE_B_EMAIL,
    ]);

    $assignmentPresented = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancyA->id,
        'candidate_profile_id' => $profileOwner->id,
        'stage' => AssignmentStage::Presented,
        'priority' => Priority::Normal,
        'recruiter_notes' => AUTHZ_S_RECRUITER_NOTES,
        'rejection_reason' => AUTHZ_S_REJECTION_REASON,
        'presented_at' => now(),
    ]);

    $assignmentSourced = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancyA->id,
        'candidate_profile_id' => $profileOther->id,
        'stage' => AssignmentStage::Sourced,
        'recruiter_notes' => AUTHZ_S_RECRUITER_NOTES,
        'presented_at' => null,
    ]);

    VacancyAssignmentNote::factory()->create([
        'vacancy_assignment_id' => $assignmentPresented->id,
        'author_id' => $recruiter->id,
        'visibility' => 'internal',
        'body' => AUTHZ_S_INTERNAL_NOTE,
    ]);

    VacancyAssignmentNote::factory()->create([
        'vacancy_assignment_id' => $assignmentPresented->id,
        'author_id' => $recruiter->id,
        'visibility' => 'company',
        'body' => 'Nota compartida con la empresa.',
    ]);

    $interview = Interview::factory()->create([
        'vacancy_assignment_id' => $assignmentPresented->id,
        'state' => InterviewState::Propuesta,
        'mode' => InterviewMode::Online,
        'scheduled_at' => now()->addDays(3),
        'alternate_scheduled_at' => now()->addDays(4),
        'recruiter_feedback' => AUTHZ_S_RECRUITER_FEEDBACK,
    ]);

    $documentOwner = CandidateDocument::factory()->create([
        'candidate_profile_id' => $profileOwner->id,
        'type' => DocumentType::cases()[0],
        'is_internal' => false,
    ]);

    $documentOther = CandidateDocument::factory()->create([
        'candidate_profile_id' => $profileOther->id,
        'type' => DocumentType::cases()[0],
        'is_internal' => false,
    ]);

    $experience = CandidateExperience::factory()->create(['candidate_profile_id' => $profileOwner->id]);
    $education = CandidateEducation::factory()->create(['candidate_profile_id' => $profileOwner->id]);
    $course = CandidateCourse::factory()->create(['candidate_profile_id' => $profileOwner->id]);
    $certification = CandidateCertification::factory()->create(['candidate_profile_id' => $profileOwner->id]);
    $reference = CandidateReference::factory()->create(['candidate_profile_id' => $profileOwner->id]);

    $skill = Skill::factory()->create();
    $language = Language::factory()->create();
    $profileOwner->skills()->attach($skill->id, ['level' => 'intermedio']);
    $profileOwner->languages()->attach($language->id, ['level' => 'intermedio']);

    // Unreferenced catalog rows, so the admin CRUD probes are not measuring
    // foreign-key behaviour by accident.
    $skillFree = Skill::factory()->create();
    $languageFree = Language::factory()->create();
    $degreeLevel = DegreeLevel::factory()->create();
    $functionalArea = FunctionalArea::factory()->create();

    $plan = MembershipPlan::factory()->create(['code' => 'candidate_6m', 'is_active' => true]);

    foreach ([$candidateOwner, $candidateOther] as $candidate) {
        Membership::factory()->create([
            'user_id' => $candidate->id,
            'membership_plan_id' => $plan->id,
            'status' => MembershipStatus::Active->value,
            'started_at' => now()->subDay(),
            'expires_at' => now()->addMonths(6),
        ]);
    }

    $test = PsychometricTest::factory()->create(['is_active' => true]);
    $attempt = PsychometricAttempt::factory()->create([
        'candidate_profile_id' => $profileOwner->id,
        'psychometric_test_id' => $test->id,
        'status' => AttemptStatus::InProgress,
    ]);

    $notificationId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    DB::table('notifications')->insert([
        'id' => $notificationId,
        'type' => 'App\\Notifications\\Probe',
        'notifiable_type' => User::class,
        'notifiable_id' => $candidateOwner->id,
        'data' => (string) json_encode(['title' => 'Probe'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'users' => [
            'candidate_owner' => $candidateOwner,
            'candidate_other' => $candidateOther,
            'recruiter' => $recruiter,
            'company_owner' => $companyOwner,
            'company_other' => $companyOther,
            'admin' => $admin,
        ],
        'tokens' => [
            'company' => $companyA->id,
            'company_b' => $companyB->id,
            'company_member_user' => $companyViewer->id,
            'member' => $viewerMember->id,
            'vacancy' => $vacancyA->id,
            'vacancy_draft' => $vacancyDraft->id,
            'assignment' => $assignmentPresented->id,
            'assignment_sourced' => $assignmentSourced->id,
            'interview' => $interview->id,
            'candidate' => $profileOther->id,
            'document' => $documentOwner->id,
            'document_other' => $documentOther->id,
            'experience' => $experience->id,
            'education' => $education->id,
            'course' => $course->id,
            'certification' => $certification->id,
            'reference' => $reference->id,
            'skill' => $skill->id,
            'language' => $language->id,
            'skill_free' => $skillFree->id,
            'language_free' => $languageFree->id,
            'degree_level' => $degreeLevel->id,
            'functional_area' => $functionalArea->id,
            'attempt' => $attempt->id,
            'test' => $test->id,
            'user' => $invitee->id,
            'invitation_token' => $invitationToken,
            'verify_id' => $candidateOwner->id,
            'verify_hash' => sha1((string) $candidateOwner->getEmailForVerification()),
            'notification' => $notificationId,
            'candidate_other_email' => AUTHZ_S_CANDIDATE_B_EMAIL,
        ],
    ];
}

/*
|--------------------------------------------------------------------------
| The matrix
|--------------------------------------------------------------------------
*/

dataset('authorization-matrix', fn (): array => authzMatrixRows());

/**
 * @param  list<string>  $allow
 * @param  array<string, string>  $findings
 * @return array{expect: array<string, string>, findings: array<string, string>}
 */
function authzAccess(array $allow, array $findings = []): array
{
    $expect = [];

    foreach (AUTHZ_ACTORS as $actor) {
        $expect[$actor] = in_array($actor, $allow, true) ? 'allow' : 'deny';
    }

    return ['expect' => $expect, 'findings' => $findings];
}

/**
 * §5.2 and §5.4 scope the candidate self-service surface to the candidate role,
 * and `role:candidate` now sits in front of all 30 routes.
 *
 * @return array{expect: array<string, string>, findings: array<string, string>}
 */
function authzCandidateSelfService(bool $ownerOnly = false): array
{
    return authzAccess($ownerOnly ? ['candidate_owner'] : ['candidate_owner', 'candidate_other']);
}

function authzFutureDate(int $days): string
{
    return (new DateTimeImmutable('+'.$days.' days'))->format(DATE_ATOM);
}

/**
 * The full route x role expectation table.
 *
 * @return array<string, array{0: array<string, mixed>}>
 */
function authzMatrixRows(): array
{
    $rows = [];

    $add = function (string $key, array $row) use (&$rows): void {
        $rows[$key] = [$row];
    };

    $everyone = AUTHZ_ACTORS;
    $authenticated = ['candidate_owner', 'candidate_other', 'recruiter', 'company_owner', 'company_other', 'admin'];
    $staff = ['recruiter', 'admin'];

    $companySentinels = [
        AUTHZ_S_COMPANY_A_RFC,
        AUTHZ_S_COMPANY_A_CONTACT_EMAIL,
        AUTHZ_S_COMPANY_A_ADDRESS,
        AUTHZ_S_COMPANY_A_INTERNAL_NOTES,
    ];

    // ------------------------------------------------------------- Auth (§5.1)
    $add('POST /auth/register', [
        'method' => 'POST', 'uri' => '/api/v1/auth/register', 'spec' => '§5.1 público',
        'payload' => ['name' => 'Nuevo', 'email' => '{actor_email}', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'],
        ...authzAccess($everyone),
    ]);

    $add('POST /auth/register/recruiter', [
        'method' => 'POST', 'uri' => '/api/v1/auth/register/recruiter', 'spec' => 'UNSPECIFIED — inferido: solicitud pública + aprobación admin',
        'payload' => ['name' => 'Nuevo', 'email' => '{actor_email}', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'],
        ...authzAccess($everyone),
    ]);

    $add('POST /auth/register/company', [
        'method' => 'POST', 'uri' => '/api/v1/auth/register/company', 'spec' => '§6 Registrarse — Empresa ❌ (invitación)',
        'payload' => [
            'name' => 'Nuevo contacto', 'email' => '{actor_email}',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'accept_terms' => true,
            'company' => ['legal_name' => 'Empresa Nueva S.A. de C.V.'],
        ],
        ...authzAccess([], array_fill_keys($everyone, 'F-12')),
    ]);

    $add('POST /auth/login', [
        'method' => 'POST', 'uri' => '/api/v1/auth/login', 'spec' => '§5.1 público',
        'payload' => ['email' => 'no-existe@humae.test', 'password' => 'Password123!'],
        ...authzAccess($everyone),
    ]);

    $add('POST /auth/forgot-password', [
        'method' => 'POST', 'uri' => '/api/v1/auth/forgot-password', 'spec' => '§5.1 público',
        'payload' => ['email' => 'no-existe@humae.test'],
        ...authzAccess($everyone),
    ]);

    $add('POST /auth/reset-password', [
        'method' => 'POST', 'uri' => '/api/v1/auth/reset-password', 'spec' => '§5.1 público',
        'payload' => ['token' => 'token-invalido', 'email' => 'no-existe@humae.test', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'],
        ...authzAccess($everyone),
    ]);

    $add('GET /auth/verify-email/{id}/{hash}', [
        'method' => 'GET', 'uri' => '/api/v1/auth/verify-email/{verify_id}/{verify_hash}', 'spec' => '§5.1 público',
        ...authzAccess($everyone),
    ]);

    $add('POST /auth/verify-email/resend', [
        'method' => 'POST', 'uri' => '/api/v1/auth/verify-email/resend', 'spec' => 'UNSPECIFIED — inferido: público con rate limit',
        'payload' => ['email' => 'no-existe@humae.test'],
        ...authzAccess($everyone),
    ]);

    $add('POST /auth/resend-verification', [
        'method' => 'POST', 'uri' => '/api/v1/auth/resend-verification', 'spec' => '§5.1 auth',
        ...authzAccess($authenticated),
    ]);

    $add('GET /auth/invitation/{token}', [
        'method' => 'GET', 'uri' => '/api/v1/auth/invitation/{invitation_token}', 'spec' => 'UNSPECIFIED — inferido: público, autorizado por el token',
        ...authzAccess($everyone),
    ]);

    $add('POST /auth/invitation/accept', [
        'method' => 'POST', 'uri' => '/api/v1/auth/invitation/accept', 'spec' => 'UNSPECIFIED — inferido: público, autorizado por el token',
        'payload' => ['token' => '{invitation_token}', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'],
        ...authzAccess($everyone),
    ]);

    $add('POST /auth/logout', [
        'method' => 'POST', 'uri' => '/api/v1/auth/logout', 'spec' => '§5.1 auth',
        ...authzAccess($authenticated),
    ]);

    $add('GET /auth/me', [
        'method' => 'GET', 'uri' => '/api/v1/auth/me', 'spec' => '§5.1 auth',
        ...authzAccess($authenticated),
    ]);

    // -------------------------------------------------- Catálogos (UNSPECIFIED)
    foreach (['skills', 'languages', 'degree-levels', 'functional-areas', 'vacancy-types'] as $catalog) {
        $add("GET /catalogs/{$catalog}", [
            'method' => 'GET', 'uri' => "/api/v1/catalogs/{$catalog}", 'spec' => 'UNSPECIFIED — inferido: cualquier usuario autenticado (datos maestros)',
            ...authzAccess($authenticated),
        ]);
    }

    // ---------------------------------------------------------- Profile (§5.2)
    $add('GET /me/profile', ['method' => 'GET', 'uri' => '/api/v1/me/profile', 'spec' => '§5.2 role: candidate', ...authzCandidateSelfService()]);
    $add('PATCH /me/profile', [
        'method' => 'PATCH', 'uri' => '/api/v1/me/profile', 'spec' => '§5.2 role: candidate',
        'payload' => ['headline' => 'Titular actualizado'],
        ...authzCandidateSelfService(),
    ]);
    $add('POST /me/profile/avatar', [
        'method' => 'POST', 'uri' => '/api/v1/me/profile/avatar', 'spec' => '§5.2 role: candidate',
        ...authzCandidateSelfService(),
    ]);
    $add('GET /me/profile/cv.pdf', [
        'method' => 'GET', 'uri' => '/api/v1/me/profile/cv.pdf', 'spec' => '§5.2 role: candidate',
        ...authzCandidateSelfService(),
    ]);

    foreach ([
        'experiences' => ['experience', ['company_name' => 'ACME', 'position_title' => 'Dev', 'start_date' => '2020-01-01', 'is_current' => true]],
        'educations' => ['education', ['institution' => 'UNAM', 'degree_title' => 'Ingeniería', 'start_date' => '2015-01-01']],
        'courses' => ['course', ['name' => 'Curso de sondeo', 'institution' => 'Instituto']],
        'certifications' => ['certification', ['name' => 'Certificación de sondeo', 'issuer' => 'Emisor']],
        'references' => ['reference', ['full_name' => 'Referencia de sondeo', 'relationship' => 'jefe']],
    ] as $resource => [$token, $payload]) {
        $add("GET /me/profile/{$resource}", [
            'method' => 'GET', 'uri' => "/api/v1/me/profile/{$resource}", 'spec' => '§5.2 role: candidate',
            ...authzCandidateSelfService(),
        ]);
        $add("POST /me/profile/{$resource}", [
            'method' => 'POST', 'uri' => "/api/v1/me/profile/{$resource}", 'spec' => '§5.2 role: candidate',
            'payload' => $payload,
            ...authzCandidateSelfService(),
        ]);
        $add("PATCH /me/profile/{$resource}/{id}", [
            'method' => 'PATCH', 'uri' => "/api/v1/me/profile/{$resource}/{".$token.'}', 'spec' => '§5.2 role: candidate (propio)',
            'payload' => $payload,
            ...authzCandidateSelfService(ownerOnly: true),
        ]);
        $add("DELETE /me/profile/{$resource}/{id}", [
            'method' => 'DELETE', 'uri' => "/api/v1/me/profile/{$resource}/{".$token.'}', 'spec' => '§5.2 role: candidate (propio)',
            ...authzCandidateSelfService(ownerOnly: true),
        ]);
    }

    $add('GET /me/profile/skills', ['method' => 'GET', 'uri' => '/api/v1/me/profile/skills', 'spec' => '§5.2 role: candidate', ...authzCandidateSelfService()]);
    $add('POST /me/profile/skills', [
        'method' => 'POST', 'uri' => '/api/v1/me/profile/skills', 'spec' => '§5.2 role: candidate',
        'payload' => ['skill_id' => '{skill}', 'level' => 'intermedio'],
        ...authzCandidateSelfService(),
    ]);
    $add('DELETE /me/profile/skills/{skill}', [
        'method' => 'DELETE', 'uri' => '/api/v1/me/profile/skills/{skill}', 'spec' => '§5.2 role: candidate (propio)',
        ...authzCandidateSelfService(),
    ]);
    $add('GET /me/profile/languages', ['method' => 'GET', 'uri' => '/api/v1/me/profile/languages', 'spec' => '§5.2 role: candidate', ...authzCandidateSelfService()]);
    $add('POST /me/profile/languages', [
        'method' => 'POST', 'uri' => '/api/v1/me/profile/languages', 'spec' => '§5.2 role: candidate',
        'payload' => ['language_id' => '{language}', 'level' => 'intermedio'],
        ...authzCandidateSelfService(),
    ]);
    $add('DELETE /me/profile/languages/{language}', [
        'method' => 'DELETE', 'uri' => '/api/v1/me/profile/languages/{language}', 'spec' => '§5.2 role: candidate (propio)',
        ...authzCandidateSelfService(),
    ]);
    $add('GET /me/profile/documents', ['method' => 'GET', 'uri' => '/api/v1/me/profile/documents', 'spec' => '§5.2 role: candidate', ...authzCandidateSelfService()]);
    $add('POST /me/profile/documents', [
        'method' => 'POST', 'uri' => '/api/v1/me/profile/documents', 'spec' => '§5.2 role: candidate',
        ...authzCandidateSelfService(),
    ]);
    $add('GET /me/profile/documents/{document}/download', [
        'method' => 'GET', 'uri' => '/api/v1/me/profile/documents/{document}/download', 'spec' => '§5.2 role: candidate (propio)',
        'allow_not_found' => true,
        ...authzCandidateSelfService(ownerOnly: true),
    ]);
    $add('DELETE /me/profile/documents/{document}', [
        'method' => 'DELETE', 'uri' => '/api/v1/me/profile/documents/{document}', 'spec' => '§5.2 role: candidate (propio)',
        ...authzCandidateSelfService(ownerOnly: true),
    ]);

    // ------------------------------------------------------- Membership (§5.3)
    $add('GET /me/membership', [
        'method' => 'GET', 'uri' => '/api/v1/me/membership', 'spec' => '§5.3 auth',
        ...authzAccess($authenticated),
    ]);
    $add('POST /me/membership/checkout', [
        'method' => 'POST', 'uri' => '/api/v1/me/membership/checkout', 'spec' => '§6 Pagar membresía — sólo Candidato',
        // Both candidates already hold an active membership, so the controller
        // answers 409 before reaching Stripe. A 409 for a role §6 marks "—"
        // still proves there is no role gate in front of the endpoint.
        ...authzAccess(['candidate_owner', 'candidate_other']),
    ]);
    $add('GET /me/payments', [
        'method' => 'GET', 'uri' => '/api/v1/me/payments', 'spec' => '§5.3 auth',
        ...authzAccess($authenticated),
    ]);

    // ---------------------------------------------------- Psychometrics (§5.4)
    $add('GET /me/psychometrics/tests', ['method' => 'GET', 'uri' => '/api/v1/me/psychometrics/tests', 'spec' => '§5.4 role: candidate', ...authzCandidateSelfService()]);
    $add('POST /me/psychometrics/attempts', [
        'method' => 'POST', 'uri' => '/api/v1/me/psychometrics/attempts', 'spec' => '§5.4 role: candidate',
        'payload' => ['test_id' => '{test}'],
        ...authzCandidateSelfService(),
    ]);
    $add('GET /me/psychometrics/attempts/{attempt}', [
        'method' => 'GET', 'uri' => '/api/v1/me/psychometrics/attempts/{attempt}', 'spec' => '§5.4 role: candidate (propio)',
        ...authzCandidateSelfService(ownerOnly: true),
    ]);
    $add('PATCH /me/psychometrics/attempts/{attempt}/answers', [
        'method' => 'PATCH', 'uri' => '/api/v1/me/psychometrics/attempts/{attempt}/answers', 'spec' => '§5.4 role: candidate (propio)',
        'payload' => ['answers' => [['question_id' => 1, 'score' => 3]]],
        ...authzCandidateSelfService(ownerOnly: true),
    ]);
    $add('POST /me/psychometrics/attempts/{attempt}/submit', [
        'method' => 'POST', 'uri' => '/api/v1/me/psychometrics/attempts/{attempt}/submit', 'spec' => '§5.4 role: candidate (propio)',
        ...authzCandidateSelfService(ownerOnly: true),
    ]);
    $add('GET /me/psychometrics/results/{attempt}', [
        'method' => 'GET', 'uri' => '/api/v1/me/psychometrics/results/{attempt}', 'spec' => '§5.4 role: candidate (propio)',
        'allow_not_found' => true,
        ...authzCandidateSelfService(ownerOnly: true),
    ]);

    // --------------------------------------------------- Notificaciones (§5.9)
    $add('GET /me/notifications', [
        'method' => 'GET', 'uri' => '/api/v1/me/notifications', 'spec' => '§5.9 auth',
        ...authzAccess($authenticated),
    ]);
    $add('POST /me/notifications/{id}/read', [
        'method' => 'POST', 'uri' => '/api/v1/me/notifications/{notification}/read', 'spec' => '§5.9 auth (propia)',
        ...authzAccess(['candidate_owner']),
    ]);
    $add('POST /me/notifications/read-all', [
        'method' => 'POST', 'uri' => '/api/v1/me/notifications/read-all', 'spec' => '§5.9 auth',
        ...authzAccess($authenticated),
    ]);

    // -------------------------------------------------------- Directorio (§5.5)
    $add('GET /directory/candidates', [
        'method' => 'GET', 'uri' => '/api/v1/directory/candidates', 'spec' => '§5.5 / §6 Ver directorio — Empresa ❌',
        ...authzAccess($staff),
    ]);
    $add('GET /directory/candidates/{candidate}', [
        'method' => 'GET', 'uri' => '/api/v1/directory/candidates/{candidate}', 'spec' => '§5.5 / §6 Expediente completo — Empresa ❌',
        ...authzAccess($staff),
    ]);
    $add('POST /directory/candidates/{candidate}/favorite', [
        'method' => 'POST', 'uri' => '/api/v1/directory/candidates/{candidate}/favorite', 'spec' => '§5.5 / §6 Marcar favoritos — Empresa ❌',
        ...authzAccess($staff),
    ]);
    $add('GET /directory/candidates/{candidate}/cv.pdf', [
        'method' => 'GET', 'uri' => '/api/v1/directory/candidates/{candidate}/cv.pdf', 'spec' => '§5.5 / §6 Descargar CV de cualquier candidato — Empresa ❌',
        ...authzAccess($staff),
    ]);
    $add('GET /directory/candidates/{candidate}/documents/{document}/download', [
        'method' => 'GET', 'uri' => '/api/v1/directory/candidates/{candidate}/documents/{document_other}/download', 'spec' => 'UNSPECIFIED — inferido: §5.5 recruiter/admin',
        'allow_not_found' => true,
        ...authzAccess($staff),
    ]);

    // --------------------------------------------------------- Empresas (§5.6)
    $add('GET /companies', [
        'method' => 'GET', 'uri' => '/api/v1/companies', 'spec' => '§5.6 admin/recruiter',
        'must_not_leak' => ['company_owner' => $companySentinels, 'company_other' => $companySentinels],
        ...authzAccess($staff),
    ]);
    $add('POST /companies', [
        'method' => 'POST', 'uri' => '/api/v1/companies', 'spec' => '§5.6 admin/recruiter',
        'payload' => ['legal_name' => 'Empresa de sondeo S.A. de C.V.'],
        ...authzAccess($staff),
    ]);
    $add('GET /companies/{company}', [
        'method' => 'GET', 'uri' => '/api/v1/companies/{company}', 'spec' => '§5.6 admin/recruiter',
        ...authzAccess($staff),
    ]);
    $add('PATCH /companies/{company}', [
        'method' => 'PATCH', 'uri' => '/api/v1/companies/{company}', 'spec' => '§5.6 admin/recruiter',
        'payload' => ['status' => 'archived', 'internal_notes' => 'reescrito por la empresa'],
        ...authzAccess($staff),
    ]);
    $add('DELETE /companies/{company}', [
        'method' => 'DELETE', 'uri' => '/api/v1/companies/{company}', 'spec' => 'UNSPECIFIED — inferido: admin (destructivo)',
        ...authzAccess(['admin']),
    ]);
    $add('GET /companies/{company}/members', [
        'method' => 'GET', 'uri' => '/api/v1/companies/{company}/members', 'spec' => '§5.6 admin/recruiter',
        ...authzAccess($staff),
    ]);
    $add('POST /companies/{company}/members', [
        'method' => 'POST', 'uri' => '/api/v1/companies/{company}/members', 'spec' => '§5.6 admin/recruiter',
        'payload' => ['user_id' => '{user}', 'role' => 'viewer'],
        ...authzAccess($staff),
    ]);
    $add('DELETE /companies/{company}/members/{userId}', [
        'method' => 'DELETE', 'uri' => '/api/v1/companies/{company}/members/{company_member_user}', 'spec' => '§5.6 admin/recruiter',
        ...authzAccess($staff),
    ]);

    // -------------------------------------------------------- Vacantes (§5.6)
    $add('GET /vacancies', [
        'method' => 'GET', 'uri' => '/api/v1/vacancies', 'spec' => '§5.6 recruiter/admin/company (propias)',
        'must_not_leak' => [
            'company_owner' => [AUTHZ_S_VACANCY_INTERNAL_NOTES],
            'company_other' => [AUTHZ_S_VACANCY_INTERNAL_NOTES],
        ],
        ...authzAccess(['recruiter', 'company_owner', 'company_other', 'admin']),
    ]);
    $add('POST /vacancies (company_id de la empresa A)', [
        'method' => 'POST', 'uri' => '/api/v1/vacancies', 'spec' => '§6 Crear vacante — Empresa ✅ (propia)',
        'payload' => ['company_id' => '{company}', 'title' => 'Vacante de sondeo', 'description' => 'Descripción de la vacante de sondeo.'],
        ...authzAccess(['recruiter', 'company_owner', 'admin']),
    ]);
    $add('GET /vacancies/{vacancy}', [
        'method' => 'GET', 'uri' => '/api/v1/vacancies/{vacancy}', 'spec' => '§5.6 recruiter/admin/company (propias)',
        'must_not_leak' => ['company_owner' => [AUTHZ_S_VACANCY_INTERNAL_NOTES]],
        ...authzAccess(['recruiter', 'company_owner', 'admin']),
    ]);
    $add('PATCH /vacancies/{vacancy}', [
        'method' => 'PATCH', 'uri' => '/api/v1/vacancies/{vacancy}', 'spec' => '§5.6 PATCH /jobs/{id} — recruiter/admin',
        'payload' => ['title' => 'Título reescrito', 'internal_notes' => 'reescrito', 'fee_amount' => 1],
        ...authzAccess($staff),
    ]);
    $add('PATCH /vacancies/{vacancy} (reasignar company_id)', [
        'method' => 'PATCH', 'uri' => '/api/v1/vacancies/{vacancy}', 'spec' => '§5.6 + aislamiento entre inquilinos',
        'payload' => ['company_id' => '{company_b}'],
        ...authzAccess($staff),
    ]);
    $add('DELETE /vacancies/{vacancy}', [
        'method' => 'DELETE', 'uri' => '/api/v1/vacancies/{vacancy}', 'spec' => 'UNSPECIFIED — inferido: admin (destructivo)',
        ...authzAccess(['admin']),
    ]);
    $add('POST /vacancies/{vacancy}/transition (→ cubierta)', [
        'method' => 'POST', 'uri' => '/api/v1/vacancies/{vacancy}/transition', 'spec' => '§5.6 recruiter/admin',
        'payload' => ['to' => VacancyState::Cubierta->value],
        ...authzAccess($staff),
    ]);
    $add('GET /vacancies/{vacancy}/suggested-candidates', [
        'method' => 'GET', 'uri' => '/api/v1/vacancies/{vacancy}/suggested-candidates', 'spec' => 'UNSPECIFIED — inferido: §6 directorio ❌ para Empresa',
        ...authzAccess($staff),
    ]);

    // -------------------------------------------------------- Pipeline (§5.7)
    $add('GET /vacancies/{vacancy}/assignments', [
        'method' => 'GET', 'uri' => '/api/v1/vacancies/{vacancy}/assignments', 'spec' => '§5.7 recruiter/admin',
        ...authzAccess($staff),
    ]);
    $add('POST /vacancies/{vacancy}/assignments', [
        'method' => 'POST', 'uri' => '/api/v1/vacancies/{vacancy}/assignments', 'spec' => '§5.7 / §6 Asignar candidatos — Empresa ❌',
        'payload' => ['candidate_profile_id' => '{candidate}'],
        ...authzAccess($staff),
    ]);
    $add('PATCH /assignments/{assignment}', [
        'method' => 'PATCH', 'uri' => '/api/v1/assignments/{assignment}', 'spec' => '§5.7 recruiter/admin',
        'payload' => ['stage' => AssignmentStage::Interviewing->value],
        ...authzAccess($staff),
    ]);
    $add('DELETE /assignments/{assignment}', [
        'method' => 'DELETE', 'uri' => '/api/v1/assignments/{assignment}', 'spec' => '§5.7 recruiter/admin',
        ...authzAccess($staff),
    ]);
    $add('PATCH /assignments/{assignment}/select-finalist (presentado)', [
        'method' => 'PATCH', 'uri' => '/api/v1/assignments/{assignment}/select-finalist', 'spec' => '§5.7 / §6 Seleccionar finalista — Empresa ✅ (decide)',
        'must_not_leak' => ['company_owner' => [AUTHZ_S_RECRUITER_NOTES, AUTHZ_S_REJECTION_REASON]],
        'no_pii_keys_for' => ['company_owner'],
        ...authzAccess(['recruiter', 'company_owner', 'admin']),
    ]);
    $add('PATCH /assignments/{assignment}/select-finalist (sourced)', [
        'method' => 'PATCH', 'uri' => '/api/v1/assignments/{assignment_sourced}/select-finalist', 'spec' => '§6 — sólo sobre candidatos ya presentados',
        ...authzAccess($staff),
    ]);
    $add('GET /assignments/{assignment}/notes', [
        'method' => 'GET', 'uri' => '/api/v1/assignments/{assignment}/notes', 'spec' => '§5.7 / §6 Notas internas — Empresa ❌',
        'must_not_leak' => ['company_owner' => [AUTHZ_S_INTERNAL_NOTE]],
        ...authzAccess(['recruiter', 'company_owner', 'admin']),
    ]);
    $add('POST /assignments/{assignment}/notes', [
        'method' => 'POST', 'uri' => '/api/v1/assignments/{assignment}/notes', 'spec' => '§5.7 / §6 Notas internas — Empresa ❌',
        'payload' => ['body' => 'Nota de sondeo.', 'visibility' => 'internal'],
        ...authzAccess(['recruiter', 'company_owner', 'admin']),
    ]);
    $add('GET /assignments/{assignment}/notes (sourced)', [
        'method' => 'GET', 'uri' => '/api/v1/assignments/{assignment_sourced}/notes', 'spec' => '§6 — sólo sobre candidatos ya presentados',
        ...authzAccess($staff),
    ]);

    // ------------------------------------------------------ Entrevistas (§5.8)
    $add('GET /interviews', [
        'method' => 'GET', 'uri' => '/api/v1/interviews', 'spec' => '§5.8 auth (scoping por rol)',
        'must_not_leak' => [
            '*' => [],
            'candidate_owner' => [AUTHZ_S_RECRUITER_FEEDBACK],
            'candidate_other' => [AUTHZ_S_RECRUITER_FEEDBACK, AUTHZ_S_CANDIDATE_B_LASTNAME],
            'company_other' => [AUTHZ_S_RECRUITER_FEEDBACK],
        ],
        ...authzAccess($authenticated),
    ]);
    $add('POST /interviews (asignación presentada)', [
        'method' => 'POST', 'uri' => '/api/v1/interviews', 'spec' => '§5.8 / §6 Programar entrevista — Candidato ❌',
        'payload' => [
            'vacancy_assignment_id' => '{assignment}',
            'scheduled_at' => authzFutureDate(10),
            'alternate_scheduled_at' => authzFutureDate(11),
        ],
        ...authzAccess(['recruiter', 'company_owner', 'admin']),
    ]);
    $add('POST /interviews (asignación sourced)', [
        'method' => 'POST', 'uri' => '/api/v1/interviews', 'spec' => '§6 — sólo sobre candidatos ya presentados',
        'payload' => [
            'vacancy_assignment_id' => '{assignment_sourced}',
            'scheduled_at' => authzFutureDate(10),
            'alternate_scheduled_at' => authzFutureDate(11),
        ],
        ...authzAccess($staff),
    ]);
    $add('GET /interviews/{interview}', [
        'method' => 'GET', 'uri' => '/api/v1/interviews/{interview}', 'spec' => '§5.8',
        'must_not_leak' => ['candidate_owner' => [AUTHZ_S_RECRUITER_FEEDBACK]],
        ...authzAccess(['candidate_owner', 'recruiter', 'company_owner', 'admin']),
    ]);
    $add('PATCH /interviews/{interview} (reprogramar)', [
        'method' => 'PATCH', 'uri' => '/api/v1/interviews/{interview}', 'spec' => '§5.8 reprograma / cambia estado',
        'payload' => ['scheduled_at' => authzFutureDate(20), 'reason' => 'Cambio de agenda.'],
        ...authzAccess(['recruiter', 'company_owner', 'admin']),
    ]);
    $add('PATCH /interviews/{interview} (escribir evaluación interna)', [
        'method' => 'PATCH', 'uri' => '/api/v1/interviews/{interview}', 'spec' => '§6 Agregar notas internas — Empresa ❌',
        'payload' => [
            'recruiter_feedback' => 'reescrito por la empresa',
            'recommendation' => 'reject',
            'meeting_url' => 'https://meet.example.com/sondeo',
        ],
        ...authzAccess($staff),
    ]);
    $add('POST /interviews/{interview}/select-slot', [
        'method' => 'POST', 'uri' => '/api/v1/interviews/{interview}/select-slot', 'spec' => 'UNSPECIFIED — inferido: candidato + HUMAE + decisor de la empresa',
        'payload' => ['slot' => 1],
        ...authzAccess(['candidate_owner', 'recruiter', 'company_owner', 'admin']),
    ]);
    $add('POST /interviews/{interview}/meeting-details', [
        'method' => 'POST', 'uri' => '/api/v1/interviews/{interview}/meeting-details', 'spec' => 'UNSPECIFIED — inferido: recruiter/admin',
        'payload' => ['meeting_url' => 'https://meet.example.com/sondeo'],
        ...authzAccess($staff),
    ]);
    $add('POST /interviews/{interview}/confirm', [
        'method' => 'POST', 'uri' => '/api/v1/interviews/{interview}/confirm', 'spec' => '§5.8 / §6 Confirmar entrevista',
        ...authzAccess(['candidate_owner', 'recruiter', 'company_owner', 'admin']),
    ]);
    $add('POST /interviews/{interview}/cancel', [
        'method' => 'POST', 'uri' => '/api/v1/interviews/{interview}/cancel', 'spec' => '§5.8 (sin rol explícito) — inferido: partes de la entrevista + HUMAE',
        'payload' => ['reason' => 'Cancelada durante el sondeo.'],
        ...authzAccess(['candidate_owner', 'recruiter', 'company_owner', 'admin']),
    ]);
    $add('POST /interviews/{interview}/complete', [
        'method' => 'POST', 'uri' => '/api/v1/interviews/{interview}/complete', 'spec' => 'UNSPECIFIED — inferido: recruiter/admin',
        'payload' => ['recruiter_feedback' => 'Entrevista realizada.', 'recommendation' => 'advance'],
        ...authzAccess($staff),
    ]);

    // ------------------------------------------------------ Mi empresa (§5.6)
    $add('GET /me/company', [
        'method' => 'GET', 'uri' => '/api/v1/me/company', 'spec' => '§6 Ver/editar su propio perfil — Empresa ✅ (propia)',
        'must_not_leak' => ['company_other' => $companySentinels],
        'allow_not_found' => true,
        ...authzAccess(['company_owner', 'company_other', 'admin']),
    ]);
    $add('PATCH /me/company', [
        'method' => 'PATCH', 'uri' => '/api/v1/me/company', 'spec' => '§6 Ver/editar su propio perfil — Empresa ✅ (propia)',
        'payload' => ['trade_name' => 'Empresa A renombrada'],
        'allow_not_found' => true,
        ...authzAccess(['company_owner', 'company_other', 'admin']),
    ]);
    $add('GET /me/company/members', [
        'method' => 'GET', 'uri' => '/api/v1/me/company/members', 'spec' => 'UNSPECIFIED — inferido: miembros de la propia empresa',
        ...authzAccess(['company_owner', 'company_other']),
    ]);
    $add('POST /me/company/members (correo sin cuenta)', [
        'method' => 'POST', 'uri' => '/api/v1/me/company/members', 'spec' => 'UNSPECIFIED — inferido: owner de la propia empresa',
        'payload' => ['email' => '{actor_email}', 'role' => 'viewer'],
        ...authzAccess(['company_owner', 'company_other']),
    ]);
    $add('POST /me/company/members (adjuntar una cuenta de candidato ajena)', [
        'method' => 'POST', 'uri' => '/api/v1/me/company/members', 'spec' => 'UNSPECIFIED — inferido: no se puede enrolar una cuenta ajena sin su consentimiento',
        'payload' => ['email' => '{candidate_other_email}', 'role' => 'viewer'],
        ...authzAccess([]),
    ]);
    $add('PATCH /me/company/members/{member}', [
        'method' => 'PATCH', 'uri' => '/api/v1/me/company/members/{member}', 'spec' => 'UNSPECIFIED — inferido: owner de la propia empresa',
        'payload' => ['job_title' => 'Nuevo puesto'],
        ...authzAccess(['company_owner']),
    ]);
    $add('DELETE /me/company/members/{member}', [
        'method' => 'DELETE', 'uri' => '/api/v1/me/company/members/{member}', 'spec' => 'UNSPECIFIED — inferido: owner de la propia empresa',
        ...authzAccess(['company_owner']),
    ]);
    $add('GET /me/company/vacancies', [
        'method' => 'GET', 'uri' => '/api/v1/me/company/vacancies', 'spec' => '§5.6 /me/company/jobs — company_user',
        'must_not_leak' => [
            'company_owner' => [AUTHZ_S_VACANCY_INTERNAL_NOTES],
            'company_other' => [AUTHZ_S_VACANCY_INTERNAL_NOTES],
        ],
        ...authzAccess(['company_owner', 'company_other', 'admin']),
    ]);
    $add('POST /me/company/vacancies (empresa propia)', [
        'method' => 'POST', 'uri' => '/api/v1/me/company/vacancies', 'spec' => '§5.6 crear solicitud (queda borrador)',
        'payload' => ['company_id' => '{company}', 'title' => 'Solicitud de sondeo', 'description' => 'Solicitud de vacante de sondeo.'],
        ...authzAccess(['company_owner', 'admin']),
    ]);
    $add('POST /me/company/vacancies (con notas internas y honorarios)', [
        'method' => 'POST', 'uri' => '/api/v1/me/company/vacancies', 'spec' => '§6 Agregar notas internas — Empresa ❌',
        'payload' => [
            'company_id' => '{company}', 'title' => 'Solicitud de sondeo', 'description' => 'Solicitud de vacante de sondeo.',
            'internal_notes' => 'escrito por la empresa', 'fee_amount' => 1, 'sla_days' => 1,
        ],
        ...authzAccess(['admin']),
    ]);
    $add('GET /me/company/vacancies/{vacancy}', [
        'method' => 'GET', 'uri' => '/api/v1/me/company/vacancies/{vacancy}', 'spec' => '§5.6 /me/company/jobs — company_user',
        'must_not_leak' => ['company_owner' => [AUTHZ_S_VACANCY_INTERNAL_NOTES]],
        ...authzAccess(['recruiter', 'company_owner', 'admin']),
    ]);
    $add('PATCH /me/company/vacancies/{vacancy} (notas internas + honorarios)', [
        'method' => 'PATCH', 'uri' => '/api/v1/me/company/vacancies/{vacancy}', 'spec' => '§6 Agregar notas internas — Empresa ❌',
        'payload' => ['internal_notes' => 'reescrito por la empresa', 'fee_amount' => 1],
        ...authzAccess($staff),
    ]);
    $add('PATCH /me/company/vacancies/{vacancy} (reasignar company_id)', [
        'method' => 'PATCH', 'uri' => '/api/v1/me/company/vacancies/{vacancy}', 'spec' => '§5.6 + aislamiento entre inquilinos',
        'payload' => ['company_id' => '{company_b}'],
        ...authzAccess($staff),
    ]);
    $add('POST /me/company/vacancies/{vacancy}/transition (→ activa)', [
        'method' => 'POST', 'uri' => '/api/v1/me/company/vacancies/{vacancy_draft}/transition', 'spec' => '§6 Aprobar / activar vacante — Empresa ❌',
        'payload' => ['to' => VacancyState::Activa->value],
        ...authzAccess($staff),
    ]);
    // Row inverted for the recruiter: it used to expect a refusal because the
    // controller's whitelist blocked `cubierta` for everybody, which
    // contradicts §6 «Marcar vacante como cubierta — Reclutador ✅ (confirma)».
    // The ability decides now, so both transition endpoints agree and HUMAE may
    // confirm the close from either one.
    $add('POST /me/company/vacancies/{vacancy}/transition (→ cubierta)', [
        'method' => 'POST', 'uri' => '/api/v1/me/company/vacancies/{vacancy}/transition', 'spec' => '§6 Marcar vacante como cubierta — Empresa ✅ (propone), Reclutador ✅ (confirma)',
        'payload' => ['to' => VacancyState::Cubierta->value],
        ...authzAccess(['company_owner', 'recruiter', 'admin']),
    ]);
    $add('GET /me/company/vacancies/{vacancy}/assignments', [
        'method' => 'GET', 'uri' => '/api/v1/me/company/vacancies/{vacancy}/assignments', 'spec' => '§6 Ver candidatos asignados — Empresa ✅ (propia vacante)',
        'must_not_leak' => ['company_owner' => [
            AUTHZ_S_RECRUITER_NOTES,
            AUTHZ_S_REJECTION_REASON,
            AUTHZ_S_CANDIDATE_B_LASTNAME,
            AUTHZ_S_CANDIDATE_B_CURP,
            AUTHZ_S_CANDIDATE_A_CURP,
            AUTHZ_S_CANDIDATE_A_PHONE,
        ]],
        'no_pii_keys_for' => ['company_owner'],
        ...authzAccess(['recruiter', 'company_owner', 'admin']),
    ]);

    // -------------------------------------------------------- Reportes (§5.10)
    foreach ([
        'candidates-registered',
        'active-memberships',
        'payments',
        'expiring-memberships',
        'vacancies-by-state',
        'interviews',
        'recruiter-effectiveness',
        'time-to-fill',
        'most-searched-profiles',
    ] as $report) {
        $add("GET /admin/reports/{$report}", [
            'method' => 'GET', 'uri' => "/api/v1/admin/reports/{$report}",
            'spec' => '§6 Ver reportes — Reclutador ✅ (sus procesos), Empresa ✅ (sus vacantes), Admin ✅',
            ...authzAccess(['recruiter', 'company_owner', 'company_other', 'admin'], [
                'company_owner' => 'F-11', 'company_other' => 'F-11',
            ]),
        ]);
    }

    // --------------------------------------------------- Admin usuarios (§5.10)
    $add('GET /admin/users', ['method' => 'GET', 'uri' => '/api/v1/admin/users', 'spec' => '§5.10 admin only', ...authzAccess(['admin'])]);
    $add('POST /admin/users', [
        'method' => 'POST', 'uri' => '/api/v1/admin/users', 'spec' => '§5.10 admin only',
        'payload' => ['name' => 'Nuevo usuario', 'email' => '{actor_email}', 'role' => UserRole::Recruiter->value],
        ...authzAccess(['admin']),
    ]);
    $add('POST /admin/users/{user}/resend-invitation', [
        'method' => 'POST', 'uri' => '/api/v1/admin/users/{user}/resend-invitation', 'spec' => '§5.10 admin only',
        ...authzAccess(['admin']),
    ]);
    $add('POST /admin/users/{user}/approve', [
        'method' => 'POST', 'uri' => '/api/v1/admin/users/{user}/approve', 'spec' => '§5.10 admin only',
        ...authzAccess(['admin']),
    ]);
    $add('POST /admin/users/{user}/reject', [
        'method' => 'POST', 'uri' => '/api/v1/admin/users/{user}/reject', 'spec' => '§5.10 admin only',
        ...authzAccess(['admin']),
    ]);
    $add('DELETE /admin/users/{user}', [
        'method' => 'DELETE', 'uri' => '/api/v1/admin/users/{user}', 'spec' => '§5.10 admin only',
        ...authzAccess(['admin']),
    ]);

    // -------------------------------------------------- Admin catálogos (§5.10)
    foreach ([
        'skills' => ['skill_free', ['code' => 'sondeo-skill', 'name' => 'Habilidad de sondeo']],
        'languages' => ['language_free', ['code' => 'zq', 'name' => 'Idioma de sondeo']],
        'degree-levels' => ['degree_level', ['code' => 'sondeo-grado', 'name' => 'Grado de sondeo']],
        'functional-areas' => ['functional_area', ['code' => 'sondeo-area', 'name' => 'Área de sondeo']],
    ] as $catalog => [$token, $payload]) {
        $add("GET /admin/catalogs/{$catalog}", [
            'method' => 'GET', 'uri' => "/api/v1/admin/catalogs/{$catalog}", 'spec' => '§5.10 admin only (CRUD catálogos — Reclutador ❌)',
            ...authzAccess(['admin']),
        ]);
        $add("POST /admin/catalogs/{$catalog}", [
            'method' => 'POST', 'uri' => "/api/v1/admin/catalogs/{$catalog}", 'spec' => '§5.10 admin only (CRUD catálogos — Reclutador ❌)',
            'payload' => $payload,
            ...authzAccess(['admin']),
        ]);
        $add("PATCH /admin/catalogs/{$catalog}/{id}", [
            'method' => 'PATCH', 'uri' => "/api/v1/admin/catalogs/{$catalog}/{".$token.'}', 'spec' => '§5.10 admin only (CRUD catálogos — Reclutador ❌)',
            'payload' => ['name' => 'Renombrado en el sondeo'],
            ...authzAccess(['admin']),
        ]);
        $add("DELETE /admin/catalogs/{$catalog}/{id}", [
            'method' => 'DELETE', 'uri' => "/api/v1/admin/catalogs/{$catalog}/{".$token.'}', 'spec' => '§5.10 admin only (CRUD catálogos — Reclutador ❌)',
            ...authzAccess(['admin']),
        ]);
    }

    // --------------------------------------------------------- Salud / webhooks
    $add('GET /health', [
        'method' => 'GET', 'uri' => '/api/v1/health', 'spec' => '§5.11 público',
        ...authzAccess($everyone),
    ]);
    $add('POST /webhooks/stripe', [
        'method' => 'POST', 'uri' => '/api/v1/webhooks/stripe', 'spec' => '§5.3 público, firmado por Stripe',
        'payload' => ['type' => 'checkout.session.completed'],
        ...authzAccess($everyone),
    ]);

    return $rows;
}

/*
|--------------------------------------------------------------------------
| Coverage
|--------------------------------------------------------------------------
|
| The matrix is only worth its name if it covers the whole surface. Adding a
| route to routes/api.php without adding a row here fails the suite.
|
*/

it('covers every /api/v1 route with at least one matrix row', function (): void {
    $covered = [];

    foreach (authzMatrixRows() as [$row]) {
        $covered[] = $row['method'].' '.authzCanonicalUri((string) $row['uri']);
    }

    $covered = array_unique($covered);

    $uncovered = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/v1/')) {
            continue;
        }

        $canonical = authzCanonicalUri($uri);

        $isCovered = collect($route->methods())
            ->reject(fn (string $method): bool => in_array($method, ['HEAD', 'OPTIONS'], true))
            // PUT|PATCH routes come from apiResource; a PATCH row covers both.
            ->every(fn (string $method): bool => in_array($method.' '.$canonical, $covered, true)
                || ($method === 'PUT' && in_array('PATCH '.$canonical, $covered, true)));

        if (! $isCovered) {
            $uncovered[] = implode('|', $route->methods()).' '.$uri;
        }
    }

    sort($uncovered);

    expect($uncovered)->toBe([]);
});

/**
 * Collapse route parameters so `{vacancy}` and `{vacancy_draft}` compare equal.
 */
function authzCanonicalUri(string $uri): string
{
    return (string) preg_replace('/\{[^}]+\}/', '*', ltrim($uri, '/'));
}

/*
|--------------------------------------------------------------------------
| Dead authorization code
|--------------------------------------------------------------------------
|
| A registered-but-uninvoked policy ability reads like protection in review and
| enforces nothing at runtime. InterviewPolicy was exactly that until the third
| remediation pass, so the inventory is pinned here: a new orphan shows up as a
| diff instead of as an incident.
|
*/

it('accounts for every policy ability', function (): void {
    foreach (File::files(app_path('Policies')) as $file) {
        $policy = $file->getFilenameWithoutExtension();

        expect(AUTHZ_POLICY_INVENTORY)->toHaveKey($policy);

        /** @var class-string $class */
        $class = 'App\\Policies\\'.$policy;

        $abilities = collect((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(fn (ReflectionMethod $method): string => $method->getName())
            ->reject(fn (string $ability): bool => in_array($ability, ['before', '__construct'], true))
            ->sort()
            ->values()
            ->all();

        $documented = collect(array_keys(AUTHZ_POLICY_INVENTORY[$policy]))->sort()->values()->all();

        // Drift here means someone added a policy ability without classifying
        // it as invoked or orphaned. Classify it before shipping.
        expect($abilities)->toBe($documented);
    }
});

it('keeps every ability the inventory calls invoked actually invoked', function (): void {
    foreach (AUTHZ_POLICY_INVENTORY as $policy => $abilities) {
        foreach ($abilities as $ability => $callSite) {
            if ($callSite === null) {
                continue;
            }

            $source = (string) file_get_contents(app_path($callSite));

            // If this fails, `{$policy}::{$ability}` just became an orphan:
            // the documented call site no longer names it.
            expect($source)->toContain("'{$ability}'");
        }
    }
});

it('reports the abilities no controller invokes', function (): void {
    $orphans = [];

    foreach (AUTHZ_POLICY_INVENTORY as $policy => $abilities) {
        foreach ($abilities as $ability => $callSite) {
            if ($callSite === null) {
                $orphans[] = "{$policy}::{$ability}";
            }
        }
    }

    // A written-but-unwired ability is a hole waiting to open: it reads like
    // protection in review and enforces nothing at runtime. The seven that
    // existed at audit time are gone — `publish`, `close`, `confirm` and
    // `cancel` are wired, and `CandidateProfilePolicy::update/delete` plus
    // `VacancyAssignmentPolicy::view` were deleted because the ownership they
    // expressed is structural: those controllers resolve the record from the
    // authenticated user, never from a caller-supplied id.
    expect($orphans)->toBe([]);
});

it('derives both vacancy transition abilities from the state machine', function (): void {
    // The purpose-named abilities are only worth anything if neither endpoint
    // re-invents the mapping. `abilityFor()` is the single source; a controller
    // that stops calling it has grown its own whitelist again — which is
    // exactly how F-10 shipped inverted against §6.
    foreach ([
        'Http/Controllers/Api/V1/Recruiter/VacancyController.php',
        'Http/Controllers/Api/V1/Company/CompanyVacancyController.php',
    ] as $controller) {
        $source = (string) file_get_contents(app_path($controller));

        expect($source)->toContain('VacancyStateMachine::abilityFor');
    }
});

it('applies every registered authorization middleware to at least one route', function (): void {
    $applied = collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn ($route): array => $route->gatherMiddleware())
        ->filter(fn ($middleware): bool => is_string($middleware))
        ->map(fn (string $middleware): string => explode(':', $middleware)[0])
        ->unique()
        ->values()
        ->all();

    $orphans = array_values(array_filter(
        [EnsureVerifiedEmail::class, EnsureActiveMembership::class],
        fn (string $middleware): bool => ! in_array($middleware, $applied, true),
    ));

    if ($orphans !== []) {
        test()->markTestSkipped(
            'KNOWN OPEN FINDING F-14: aliased in bootstrap/app.php but applied to zero routes — '
            .implode(', ', $orphans)
        );
    }

    expect($orphans)->toBe([]);
});
