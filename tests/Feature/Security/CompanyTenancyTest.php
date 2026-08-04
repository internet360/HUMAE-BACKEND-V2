<?php

declare(strict_types=1);

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Models\Vacancy;
use App\Support\Tenancy\CompanyTenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| The tenancy primitive
|--------------------------------------------------------------------------
|
| Six of the sixteen audit findings were the same omission: nobody asked "does
| this row belong to my company?". These probes pin the answer down at the
| primitive, not at each endpoint, so a regression shows up here first.
|
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->companyA = Company::factory()->create(['legal_name' => 'Empresa A S.A. de C.V.']);
    $this->companyB = Company::factory()->create(['legal_name' => 'Empresa B S.A. de C.V.']);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $this->companyA->id,
        'user_id' => $this->owner->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    $this->outsider = User::factory()->create();
    $this->outsider->assignRole(UserRole::CompanyUser->value);
    CompanyMember::factory()->create([
        'company_id' => $this->companyB->id,
        'user_id' => $this->outsider->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    $this->recruiter = User::factory()->create();
    $this->recruiter->assignRole(UserRole::Recruiter->value);

    $this->candidate = User::factory()->create();
    $this->candidate->assignRole(UserRole::Candidate->value);

    $this->vacancyA = Vacancy::factory()->create(['company_id' => $this->companyA->id]);
    Vacancy::factory()->create(['company_id' => $this->companyB->id]);
});

it('leaves queries unrestricted when there is no caller', function (): void {
    // Console commands, queued jobs and seeders have nobody to scope to.
    expect(Company::query()->count())->toBe(2)
        ->and(Vacancy::query()->count())->toBe(2);
});

it('leaves HUMAE staff unrestricted', function (): void {
    Sanctum::actingAs($this->recruiter);

    expect(Company::query()->count())->toBe(2)
        ->and(Vacancy::query()->count())->toBe(2);
});

it('narrows a company user to the companies it belongs to', function (): void {
    Sanctum::actingAs($this->owner);

    expect(Company::query()->pluck('id')->all())->toBe([$this->companyA->id])
        ->and(Vacancy::query()->pluck('id')->all())->toBe([$this->vacancyA->id]);
});

it('gives a caller with no membership nothing rather than everything', function (): void {
    // The failure mode that matters: a role nobody thought about must fall to
    // the empty set, never to an unfiltered query.
    Sanctum::actingAs($this->candidate);

    expect(Company::query()->count())->toBe(0)
        ->and(Vacancy::query()->count())->toBe(0)
        ->and(CompanyMember::query()->count())->toBe(0);
});

it('hides another tenant row from route model binding', function (): void {
    Sanctum::actingAs($this->outsider);

    // `Vacancy::find()` is what the router calls to resolve {vacancy}.
    expect(Vacancy::find($this->vacancyA->id))->toBeNull()
        ->and(Company::find($this->companyA->id))->toBeNull();
});

it('lets a caller escape the scope only when it says so out loud', function (): void {
    Sanctum::actingAs($this->owner);

    expect(Vacancy::query()->count())->toBe(1)
        ->and(Vacancy::acrossCompanies()->count())->toBe(2);
});

it('keeps relations that traverse from an authorized parent readable', function (): void {
    // A candidate reads the vacancy of its own interview through the
    // assignment. Re-asking the tenancy question there would deny a read the
    // domain requires, so those relations opt out explicitly.
    Sanctum::actingAs($this->candidate);

    $vacancy = Vacancy::acrossCompanies()->findOrFail($this->vacancyA->id);

    expect($vacancy->company?->id)->toBe($this->companyA->id)
        ->and($vacancy->company?->members()->count())->toBe(1);
});

it('rejects a company id the caller does not belong to', function (): void {
    $tenancy = app(CompanyTenancy::class);

    // `exists:companies,id` proves the row is real. This proves it is yours.
    expect(fn () => $tenancy->assertBelongsTo($this->outsider, $this->companyA->id))
        ->toThrow(AuthorizationException::class);

    $tenancy->assertBelongsTo($this->owner, $this->companyA->id);
    $tenancy->assertBelongsTo($this->recruiter, $this->companyA->id);

    expect(true)->toBeTrue();
});

it('forgets the memoised membership when the tenancy map changes', function (): void {
    $tenancy = app(CompanyTenancy::class);

    expect($tenancy->companyIdsFor($this->owner))->toBe([$this->companyA->id]);

    CompanyMember::factory()->create([
        'company_id' => $this->companyB->id,
        'user_id' => $this->owner->id,
        'role' => CompanyMemberRole::Viewer->value,
    ]);

    expect($tenancy->companyIdsFor($this->owner))
        ->toBe([$this->companyA->id, $this->companyB->id]);
});
