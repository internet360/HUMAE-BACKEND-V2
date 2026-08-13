<?php

declare(strict_types=1);

use App\Enums\AssignmentStage;
use App\Enums\CandidateState;
use App\Enums\CompanyMemberRole;
use App\Enums\InterviewRequestCandidateState;
use App\Enums\InterviewRequestState;
use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\InterviewRequest;
use App\Models\InterviewRequestCandidate;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Notifications\InterviewRequestCandidateVetoedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/**
 * Bandeja de solicitudes, lado HUMAE: aceptar y vetar perfiles señalados.
 *
 * Es donde la selección del cliente se convierte —o no— en pipeline. Las dos
 * fronteras que se prueban con insistencia: que la empresa no pueda resolver su
 * propia solicitud, y que la vacante no se quede colgada cuando HUMAE veta a
 * todos.
 */
const STAFF_IR_URL = '/api/v1/interview-requests';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function sirPlan(): MembershipPlan
{
    $currency = SalaryCurrency::where('code', 'MXN')->first()
        ?? SalaryCurrency::factory()->create(['code' => 'MXN']);

    return MembershipPlan::where('code', 'candidate_6m')->first()
        ?? MembershipPlan::factory()->create([
            'code' => 'candidate_6m',
            'salary_currency_id' => $currency->id,
        ]);
}

function sirCandidate(): CandidateProfile
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);

    Membership::factory()->create([
        'user_id' => $user->id,
        'membership_plan_id' => sirPlan()->id,
        'status' => MembershipStatus::Active,
        'started_at' => now()->subDay(),
        'expires_at' => now()->addDays(100),
    ]);

    return CandidateProfile::factory()->create([
        'user_id' => $user->id,
        'state' => CandidateState::Activo->value,
    ]);
}

/**
 * Una solicitud pendiente con `$count` perfiles señalados, sobre una vacante en
 * `solicitada` — el estado en el que la deja el flujo del empleador.
 *
 * @return array{0: InterviewRequest, 1: list<InterviewRequestCandidate>, 2: User}
 */
function sirRequest(int $count = 2): array
{
    $companyUser = User::factory()->create();
    $companyUser->assignRole(UserRole::CompanyUser->value);

    $company = Company::factory()->create();
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $companyUser->id,
        'role' => CompanyMemberRole::Owner->value,
        'is_primary_contact' => true,
        'accepted_at' => now(),
    ]);

    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::Solicitada->value,
    ]);

    $request = InterviewRequest::factory()->create([
        'company_id' => $company->id,
        'vacancy_id' => $vacancy->id,
        'requested_by_user_id' => $companyUser->id,
        'state' => InterviewRequestState::Pendiente->value,
    ]);

    $items = [];
    for ($i = 0; $i < $count; $i++) {
        $items[] = InterviewRequestCandidate::factory()->create([
            'interview_request_id' => $request->id,
            'candidate_profile_id' => sirCandidate()->id,
        ]);
    }

    return [$request, $items, $companyUser];
}

function sirActAsRecruiter(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($user);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Aceptar
|--------------------------------------------------------------------------
*/

it('creates the assignment already presented when HUMAE accepts a profile', function (): void {
    [$request, $items] = sirRequest();
    sirActAsRecruiter();

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/accept")
        ->assertOk();

    $item = $items[0]->fresh();

    expect($item->state)->toBe(InterviewRequestCandidateState::Aceptado)
        ->and($item->vacancy_assignment_id)->not->toBeNull()
        ->and($item->resolved_at)->not->toBeNull();

    $assignment = VacancyAssignment::find($item->vacancy_assignment_id);

    // `sourced` es la lista interna que el cliente no ve. Este candidato lo
    // eligió el cliente: dejarlo ahí sería esconderle a quien él mismo señaló.
    expect($assignment->stage)->toBe(AssignmentStage::Presented)
        ->and($assignment->vacancy_id)->toBe($request->vacancy_id);
});

it('moves the vacancy into the pipeline on the first acceptance', function (): void {
    [$request, $items] = sirRequest();
    sirActAsRecruiter();

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/accept")
        ->assertOk();

    $vacancy = Vacancy::acrossCompanies()->find($request->vacancy_id);

    expect($vacancy->state)->toBe(VacancyState::ConCandidatosAsignados);
});

it('takes the request into management on the first resolution', function (): void {
    [$request, $items] = sirRequest();
    $recruiter = sirActAsRecruiter();

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/accept")
        ->assertOk();

    $fresh = InterviewRequest::acrossCompanies()->find($request->id);

    expect($fresh->state)->toBe(InterviewRequestState::EnGestion)
        ->and($fresh->assigned_recruiter_id)->toBe($recruiter->id);
});

it('closes the request once every profile is resolved', function (): void {
    [$request, $items] = sirRequest();
    sirActAsRecruiter();

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/accept")->assertOk();
    expect(InterviewRequest::acrossCompanies()->find($request->id)->state)
        ->toBe(InterviewRequestState::EnGestion);

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[1]->id}/accept")->assertOk();

    $fresh = InterviewRequest::acrossCompanies()->find($request->id);

    expect($fresh->state)->toBe(InterviewRequestState::Atendida)
        ->and($fresh->resolved_at)->not->toBeNull();
});

it('refuses to resolve the same profile twice', function (): void {
    [$request, $items] = sirRequest();
    sirActAsRecruiter();

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/accept")->assertOk();
    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/accept")
        ->assertStatus(409);

    expect(VacancyAssignment::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Vetar
|--------------------------------------------------------------------------
*/

it('vetoes a single profile and leaves the rest of the request alive', function (): void {
    [$request, $items] = sirRequest();
    sirActAsRecruiter();

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/reject", [
        'reason' => 'Ya está en proceso avanzado con otro cliente.',
    ])->assertOk();

    expect($items[0]->fresh()->state)->toBe(InterviewRequestCandidateState::Vetado)
        ->and($items[0]->fresh()->rejection_reason)->toBe('Ya está en proceso avanzado con otro cliente.')
        ->and($items[1]->fresh()->state)->toBe(InterviewRequestCandidateState::Pendiente)
        ->and(InterviewRequest::acrossCompanies()->find($request->id)->state)
        ->toBe(InterviewRequestState::EnGestion);

    // Vetar no crea pipeline.
    expect(VacancyAssignment::count())->toBe(0);
});

it('demands a motive the company can read', function (): void {
    [$request, $items] = sirRequest();
    sirActAsRecruiter();

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/reject", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/reject", ['reason' => 'no'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

it('tells the company which profile fell and why, without naming the person', function (): void {
    [$request, $items, $companyUser] = sirRequest();
    $profile = $items[0]->candidateProfile;
    $profile->update(['first_name' => 'Mariana', 'last_name' => 'Gutierrez']);

    sirActAsRecruiter();

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/reject", [
        'reason' => 'No acepta el rango salarial publicado.',
    ])->assertOk();

    Notification::assertSentTo(
        $companyUser,
        InterviewRequestCandidateVetoedNotification::class,
        function (InterviewRequestCandidateVetoedNotification $notification) use ($profile): bool {
            $payload = $notification->toArray($profile->user);

            // Que HUMAE lo descarte no revela quién era: la regla de identidad
            // no depende del desenlace.
            return $payload['reason'] === 'No acepta el rango salarial publicado.'
                && $payload['candidate_reference'] === $profile->public_reference
                && ! str_contains((string) json_encode($payload), 'Gutierrez');
        },
    );
});

it('sends the vacancy back to open search when every profile is vetoed', function (): void {
    [$request, $items] = sirRequest();
    sirActAsRecruiter();

    foreach ($items as $item) {
        $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$item->id}/reject", [
            'reason' => 'No cumple el perfil solicitado por el cliente.',
        ])->assertOk();
    }

    $fresh = InterviewRequest::acrossCompanies()->find($request->id);
    $vacancy = Vacancy::acrossCompanies()->find($request->vacancy_id);

    expect($fresh->state)->toBe(InterviewRequestState::Atendida)
        // Sin este desagüe la vacante se queda en `solicitada` esperando
        // candidatos que ya no van a llegar por ese camino.
        ->and($vacancy->state)->toBe(VacancyState::EnBusqueda);
});

it('keeps the vacancy in the pipeline when at least one profile survived', function (): void {
    [$request, $items] = sirRequest();
    sirActAsRecruiter();

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/accept")->assertOk();
    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[1]->id}/reject", [
        'reason' => 'Cambió de giro y ya no aplica al puesto.',
    ])->assertOk();

    $vacancy = Vacancy::acrossCompanies()->find($request->vacancy_id);

    expect($vacancy->state)->toBe(VacancyState::ConCandidatosAsignados);
});

/*
|--------------------------------------------------------------------------
| Autorización — la curación es de HUMAE
|--------------------------------------------------------------------------
*/

it('does not let the owning company resolve its own request', function (): void {
    [$request, $items, $companyUser] = sirRequest();
    Sanctum::actingAs($companyUser);

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/accept")
        ->assertForbidden();

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/reject", [
        'reason' => 'Me autoapruebo el candidato, gracias.',
    ])->assertForbidden();

    expect($items[0]->fresh()->state)->toBe(InterviewRequestCandidateState::Pendiente)
        ->and(VacancyAssignment::count())->toBe(0);
});

it('locks a candidate out of the staff tray', function (): void {
    [$request] = sirRequest();

    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $this->getJson(STAFF_IR_URL)->assertForbidden();

    // 404 y no 403: el binding corre antes que el middleware de rol, y para un
    // candidato el scope de tenancy resuelve el conjunto vacío. Que la
    // solicitud ni se confirme existir es la respuesta más estricta de las dos.
    $this->getJson(STAFF_IR_URL."/{$request->id}")->assertNotFound();
});

it('refuses a profile that belongs to another request', function (): void {
    [$requestA] = sirRequest(1);
    [, $itemsB] = sirRequest(1);
    sirActAsRecruiter();

    $this->postJson(STAFF_IR_URL."/{$requestA->id}/candidates/{$itemsB[0]->id}/accept")
        ->assertNotFound();

    expect($itemsB[0]->fresh()->state)->toBe(InterviewRequestCandidateState::Pendiente);
});

/*
|--------------------------------------------------------------------------
| Bandeja
|--------------------------------------------------------------------------
*/

it('lists requests across every client company', function (): void {
    sirRequest(1);
    sirRequest(1);
    sirActAsRecruiter();

    $this->getJson(STAFF_IR_URL)
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('shows HUMAE the real candidate behind each reference', function (): void {
    [$request, $items] = sirRequest(1);
    $items[0]->candidateProfile->update(['first_name' => 'Mariana', 'last_name' => 'Gutierrez']);
    sirActAsRecruiter();

    $this->getJson(STAFF_IR_URL."/{$request->id}")
        ->assertOk()
        ->assertJsonPath('data.candidates.0.candidate.first_name', 'Mariana')
        ->assertJsonPath('data.candidates.0.candidate.last_name', 'Gutierrez');
});

it('filters the tray by state', function (): void {
    [$request, $items] = sirRequest(1);
    sirRequest(1);
    sirActAsRecruiter();

    $this->postJson(STAFF_IR_URL."/{$request->id}/candidates/{$items[0]->id}/accept")->assertOk();

    $this->getJson(STAFF_IR_URL.'?state=pendiente')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson(STAFF_IR_URL.'?state=atendida')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
