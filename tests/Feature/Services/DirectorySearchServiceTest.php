<?php

declare(strict_types=1);

use App\Enums\CandidateKind;
use App\Enums\CandidateState;
use App\Enums\MembershipStatus;
use App\Models\CandidateProfile;
use App\Models\FunctionalArea;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\Skill;
use App\Models\User;
use App\Services\DirectorySearchService;
use Illuminate\Http\Request;

beforeEach(function (): void {
    $this->service = new DirectorySearchService;
    $mxn = SalaryCurrency::factory()->create(['code' => 'MXN']);
    $this->plan = MembershipPlan::factory()->create([
        'salary_currency_id' => $mxn->id,
        'duration_days' => 180,
    ]);
});

function directoryServiceMakeCandidate(array $profileAttrs = []): CandidateProfile
{
    $user = User::factory()->create();
    Membership::factory()->create([
        'user_id' => $user->id,
        'membership_plan_id' => test()->plan->id,
        'status' => MembershipStatus::Active,
        'expires_at' => now()->addDays(30),
    ]);

    return CandidateProfile::factory()->create(array_merge([
        'user_id' => $user->id,
        'state' => CandidateState::Activo,
    ], $profileAttrs));
}

it('filters out candidates without active membership by default', function (): void {
    directoryServiceMakeCandidate();

    $userNoMembership = User::factory()->create();
    CandidateProfile::factory()->create([
        'user_id' => $userNoMembership->id,
        'state' => CandidateState::Activo,
    ]);

    $result = $this->service->search(new Request);

    expect($result->total())->toBe(1);
});

it('includes candidates without active membership when has_active_membership=0', function (): void {
    directoryServiceMakeCandidate();

    $userNoMembership = User::factory()->create();
    CandidateProfile::factory()->create([
        'user_id' => $userNoMembership->id,
        'state' => CandidateState::Activo,
    ]);

    $result = $this->service->search(new Request(['has_active_membership' => '0']));

    expect($result->total())->toBe(2);
});

it('applies AND semantics when filtering by multiple skills', function (): void {
    $skillPhp = Skill::factory()->create(['name' => 'PHP']);
    $skillJs = Skill::factory()->create(['name' => 'JavaScript']);

    $candA = directoryServiceMakeCandidate();
    $candA->skills()->attach([
        $skillPhp->id => ['level' => 'avanzado'],
        $skillJs->id => ['level' => 'intermedio'],
    ]);

    $candB = directoryServiceMakeCandidate();
    $candB->skills()->attach([$skillPhp->id => ['level' => 'avanzado']]);

    $result = $this->service->search(new Request([
        'skills' => [$skillPhp->id, $skillJs->id],
    ]));

    expect($result->total())->toBe(1);
    expect($result->items()[0]->id)->toBe($candA->id);
});

it('filters by salary_max (keeps candidates willing to accept <= salary_max)', function (): void {
    directoryServiceMakeCandidate(['expected_salary_min' => 20000]);
    directoryServiceMakeCandidate(['expected_salary_min' => 50000]);
    directoryServiceMakeCandidate(['expected_salary_min' => null]);

    $result = $this->service->search(new Request(['salary_max' => '25000']));

    expect($result->total())->toBe(2); // 20000 y el null
});

it('filters by open_to_remote flag', function (): void {
    directoryServiceMakeCandidate(['open_to_remote' => true]);
    directoryServiceMakeCandidate(['open_to_remote' => false]);

    $result = $this->service->search(new Request(['open_to_remote' => '1']));

    expect($result->total())->toBe(1);
});

it('searches by q across first_name, last_name, headline, summary', function (): void {
    directoryServiceMakeCandidate(['first_name' => 'Maria', 'last_name' => 'Lopez']);
    directoryServiceMakeCandidate(['first_name' => 'Juan', 'last_name' => 'Perez']);

    $result = $this->service->search(new Request(['q' => 'Maria']));

    expect($result->total())->toBe(1);
});

it('respects per_page cap of 50', function (): void {
    foreach (range(1, 3) as $_) {
        directoryServiceMakeCandidate();
    }

    $result = $this->service->search(new Request(['per_page' => '1000']));

    expect($result->perPage())->toBe(50);
});

it('visibleStates() returns every CandidateState value', function (): void {
    $states = DirectorySearchService::visibleStates();

    foreach (CandidateState::cases() as $case) {
        expect($states)->toContain($case->value);
    }
});

it('filters candidates by candidate_kind', function (): void {
    directoryServiceMakeCandidate(['candidate_kind' => CandidateKind::Employee]);
    directoryServiceMakeCandidate(['candidate_kind' => CandidateKind::Intern]);
    directoryServiceMakeCandidate(['candidate_kind' => CandidateKind::Intern]);

    $employeeOnly = $this->service->search(new Request(['candidate_kind' => 'employee']));
    $internOnly = $this->service->search(new Request(['candidate_kind' => 'intern']));

    expect($employeeOnly->total())->toBe(1)
        ->and($internOnly->total())->toBe(2);
});

it('filters candidates by functional_area_ids[] using OR semantics', function (): void {
    $produccion = FunctionalArea::factory()->create(['code' => 'manufacturing']);
    $calidad = FunctionalArea::factory()->create(['code' => 'quality']);
    $ventas = FunctionalArea::factory()->create(['code' => 'sales']);

    $candA = directoryServiceMakeCandidate();
    $candA->functionalAreas()->attach([
        $produccion->id => ['is_primary' => true, 'sort_order' => 0],
        $calidad->id => ['is_primary' => false, 'sort_order' => 1],
    ]);

    $candB = directoryServiceMakeCandidate();
    $candB->functionalAreas()->attach([
        $ventas->id => ['is_primary' => true, 'sort_order' => 0],
    ]);

    directoryServiceMakeCandidate(); // sin áreas

    // OR: cualquier candidato que tenga producción O calidad pasa.
    $result = $this->service->search(new Request([
        'functional_area_ids' => [$produccion->id, $calidad->id],
    ]));
    expect($result->total())->toBe(1);

    // Filtro por área principal: candA tiene producción como primary.
    $primary = $this->service->search(new Request([
        'primary_functional_area_id' => $produccion->id,
    ]));
    expect($primary->total())->toBe(1);

    // Si pido producción como primary pero solo lo tiene como secundaria, no pasa.
    $primaryCalidad = $this->service->search(new Request([
        'primary_functional_area_id' => $calidad->id,
    ]));
    expect($primaryCalidad->total())->toBe(0);
});
