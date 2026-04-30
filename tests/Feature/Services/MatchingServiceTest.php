<?php

declare(strict_types=1);

use App\Enums\CandidateKind;
use App\Enums\CandidateState;
use App\Enums\MembershipStatus;
use App\Enums\VacancyTargetKind;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\DegreeLevel;
use App\Models\FunctionalArea;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\Skill;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Services\MatchingService;

beforeEach(function (): void {
    $this->service = new MatchingService;
    $mxn = SalaryCurrency::factory()->create(['code' => 'MXN']);
    $this->plan = MembershipPlan::factory()->create([
        'salary_currency_id' => $mxn->id,
        'duration_days' => 180,
    ]);
});

function matchingMakeCandidate(array $attrs = []): CandidateProfile
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
    ], $attrs));
}

function matchingMakeVacancy(array $attrs = []): Vacancy
{
    $company = Company::factory()->create();

    return Vacancy::factory()->create(array_merge([
        'company_id' => $company->id,
    ], $attrs));
}

it('scores 100 when every dimension matches perfectly', function (): void {
    $area = FunctionalArea::factory()->create();
    $degree = DegreeLevel::factory()->create();

    $vacancy = matchingMakeVacancy([
        'target_candidate_kind' => VacancyTargetKind::Intern,
        'functional_area_id' => $area->id,
        'degree_level_id' => $degree->id,
        'min_years_of_experience' => 0,
        'max_years_of_experience' => 1,
        'salary_max' => 10000,
    ]);
    $skill = Skill::factory()->create();
    $vacancy->skills()->attach($skill->id, ['is_required' => true]);

    $candidate = matchingMakeCandidate([
        'candidate_kind' => CandidateKind::Intern,
        'years_of_experience' => 0,
        'expected_salary_min' => 5000,
    ]);
    $candidate->functionalAreas()->attach($area->id, ['is_primary' => true, 'sort_order' => 0]);
    $candidate->skills()->attach($skill->id, ['level' => 'avanzado']);
    $candidate->educations()->create([
        'institution' => 'UNAM',
        'degree_level_id' => $degree->id,
    ]);

    $result = $this->service->score($vacancy, $candidate);

    expect($result['total'])->toBe(100)
        ->and($result['breakdown']['kind'])->toBe(25)
        ->and($result['breakdown']['areas'])->toBe(25)
        ->and($result['breakdown']['education'])->toBe(15)
        ->and($result['breakdown']['experience'])->toBe(15)
        ->and($result['breakdown']['skills'])->toBe(15)
        ->and($result['breakdown']['salary'])->toBe(5);
});

it('scores zero on kind when vacancy targets intern but candidate is employee', function (): void {
    $vacancy = matchingMakeVacancy(['target_candidate_kind' => VacancyTargetKind::Intern]);
    $candidate = matchingMakeCandidate(['candidate_kind' => CandidateKind::Employee]);

    $result = $this->service->score($vacancy, $candidate);

    expect($result['breakdown']['kind'])->toBe(0);
});

it('gives partial kind score when vacancy is Any', function (): void {
    $vacancy = matchingMakeVacancy(['target_candidate_kind' => VacancyTargetKind::Any]);
    $candidate = matchingMakeCandidate(['candidate_kind' => CandidateKind::Employee]);

    $result = $this->service->score($vacancy, $candidate);

    expect($result['breakdown']['kind'])->toBe(15); // 25 * 0.6
});

it('gives bonus for areas when candidate has it as primary', function (): void {
    $area = FunctionalArea::factory()->create();
    $vacancy = matchingMakeVacancy(['functional_area_id' => $area->id]);

    $primary = matchingMakeCandidate();
    $primary->functionalAreas()->attach($area->id, ['is_primary' => true, 'sort_order' => 0]);

    $secondary = matchingMakeCandidate();
    $secondary->functionalAreas()->attach($area->id, ['is_primary' => false, 'sort_order' => 0]);

    $resPrimary = $this->service->score($vacancy, $primary);
    $resSecondary = $this->service->score($vacancy, $secondary);

    expect($resPrimary['breakdown']['areas'])->toBeGreaterThan($resSecondary['breakdown']['areas']);
});

it('penalizes experience below minimum but does not zero it out', function (): void {
    $vacancy = matchingMakeVacancy([
        'min_years_of_experience' => 5,
        'max_years_of_experience' => null,
    ]);
    $candidate = matchingMakeCandidate(['years_of_experience' => 2]);

    $result = $this->service->score($vacancy, $candidate);

    expect($result['breakdown']['experience'])->toBeGreaterThan(0)
        ->and($result['breakdown']['experience'])->toBeLessThan(15);
});

it('zeros salary when candidate min is above vacancy max', function (): void {
    $vacancy = matchingMakeVacancy(['salary_max' => 20000]);
    $candidate = matchingMakeCandidate(['expected_salary_min' => 50000]);

    $result = $this->service->score($vacancy, $candidate);

    expect($result['breakdown']['salary'])->toBe(0);
});

it('suggestForVacancy excludes candidates already assigned', function (): void {
    $area = FunctionalArea::factory()->create();
    $vacancy = matchingMakeVacancy(['functional_area_id' => $area->id]);

    $cand = matchingMakeCandidate();
    $cand->functionalAreas()->attach($area->id, ['is_primary' => true, 'sort_order' => 0]);

    $sugesstedBefore = $this->service->suggestForVacancy($vacancy);
    expect(count($sugesstedBefore))->toBe(1);

    VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $cand->id,
    ]);

    $suggestedAfter = $this->service->suggestForVacancy($vacancy);
    expect(count($suggestedAfter))->toBe(0);
});

it('suggestForVacancy filters by min_score', function (): void {
    $vacancy = matchingMakeVacancy([
        'target_candidate_kind' => VacancyTargetKind::Intern,
    ]);

    matchingMakeCandidate(['candidate_kind' => CandidateKind::Intern]);
    matchingMakeCandidate(['candidate_kind' => CandidateKind::Employee]);

    // min_score=50 deja fuera al employee porque su kind score es 0
    // y el resto suma ~60. El intern obtendrá > 70.
    $suggested = $this->service->suggestForVacancy($vacancy, 70);

    expect(count($suggested))->toBe(1);
});
