<?php

declare(strict_types=1);

use App\Enums\AssignmentStage;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Interview;
use App\Models\PsychometricAttempt;
use App\Models\User;
use App\Models\VacancyAssignment;
use App\Models\VacancyAssignmentNote;
use Database\Seeders\DemoRelationalSeeder;
use Database\Seeders\JobTaxonomySeeder;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\PdfDemoSeeder;
use Database\Seeders\PsychometricBigFiveSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SalaryCurrencySeeder;
use Database\Seeders\TestAccountsSeeder;
use Illuminate\Notifications\DatabaseNotification;

beforeEach(function (): void {
    $this->seed([
        RolesAndPermissionsSeeder::class,
        SalaryCurrencySeeder::class,
        JobTaxonomySeeder::class,
        MembershipPlanSeeder::class,
        PsychometricBigFiveSeeder::class,
        PdfDemoSeeder::class,
        TestAccountsSeeder::class,
    ]);
});

it('creates at least one VacancyAssignment for every AssignmentStage case', function (): void {
    $this->seed(DemoRelationalSeeder::class);

    foreach (AssignmentStage::cases() as $stage) {
        expect(VacancyAssignment::query()->where('stage', $stage->value)->exists())->toBeTrue();
    }
});

it('links company@test.humae as a CompanyMember of humae-demo-corp', function (): void {
    $this->seed(DemoRelationalSeeder::class);

    $company = Company::where('slug', 'humae-demo-corp')->first();
    $companyUser = User::where('email', 'company@test.humae')->first();

    expect(CompanyMember::query()
        ->where('company_id', $company?->id)
        ->where('user_id', $companyUser?->id)
        ->exists())->toBeTrue();
});

it('presents at least two candidates in the busiest mid-pipeline stage', function (): void {
    $this->seed(DemoRelationalSeeder::class);

    expect(VacancyAssignment::query()->where('stage', AssignmentStage::Presented->value)->count())
        ->toBeGreaterThanOrEqual(2);
});

it('creates interviews in propuesta, confirmada and realizada states', function (): void {
    $this->seed(DemoRelationalSeeder::class);

    expect(Interview::query()->where('state', 'propuesta')->exists())->toBeTrue()
        ->and(Interview::query()->where('state', 'confirmada')->exists())->toBeTrue()
        ->and(Interview::query()->where('state', 'realizada')->exists())->toBeTrue();
});

it('creates a completed psychometric attempt with a calculated result', function (): void {
    $this->seed(DemoRelationalSeeder::class);

    $attempt = PsychometricAttempt::query()->where('status', 'completed')->first();

    expect($attempt)->not->toBeNull();
    expect($attempt?->result)->not->toBeNull();
    expect($attempt?->result?->dimension_scores)->not->toBeEmpty();
});

it('creates notifications for the candidate, the recruiter and the company user', function (): void {
    $this->seed(DemoRelationalSeeder::class);

    expect(DatabaseNotification::query()->count())->toBeGreaterThanOrEqual(6);
    expect(DatabaseNotification::query()->whereNull('read_at')->exists())->toBeTrue();
    expect(DatabaseNotification::query()->whereNotNull('read_at')->exists())->toBeTrue();
});

it('does not duplicate rows when run twice', function (): void {
    $this->seed(DemoRelationalSeeder::class);

    $assignmentsCount = VacancyAssignment::query()->count();
    $notesCount = VacancyAssignmentNote::query()->count();
    $interviewsCount = Interview::query()->count();
    $attemptsCount = PsychometricAttempt::query()->count();
    $notificationsCount = DatabaseNotification::query()->count();

    $this->seed(DemoRelationalSeeder::class);

    expect(VacancyAssignment::query()->count())->toBe($assignmentsCount);
    expect(VacancyAssignmentNote::query()->count())->toBe($notesCount);
    expect(Interview::query()->count())->toBe($interviewsCount);
    expect(PsychometricAttempt::query()->count())->toBe($attemptsCount);
    expect(DatabaseNotification::query()->count())->toBe($notificationsCount);
});

it('short-circuits when the environment is production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    // db:seed pide confirmación interactiva en producción (ConfirmableTrait);
    // --force la evita para poder probar el short-circuit propio del seeder
    // sin acoplarnos al prompt de confirmación de Artisan.
    $this->artisan('db:seed', ['--class' => DemoRelationalSeeder::class, '--force' => true]);

    expect(VacancyAssignment::query()->count())->toBe(0);
});
