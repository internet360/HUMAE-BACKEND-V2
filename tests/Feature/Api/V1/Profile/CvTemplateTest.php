<?php

declare(strict_types=1);

use App\Enums\CvTemplate;
use App\Enums\UserRole;
use App\Models\CandidateExperience;
use App\Models\CandidateProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actingCandidate(array $profile = []): User
{
    $user = User::factory()->create(['name' => 'Ana Pérez']);
    $user->assignRole(UserRole::Candidate->value);
    CandidateProfile::factory()->create([
        'user_id' => $user->id,
        'first_name' => 'Ana',
        'last_name' => 'Pérez',
        ...$profile,
    ]);

    Sanctum::actingAs($user);

    return $user;
}

it('defaults to the classic template', function (): void {
    expect(CvTemplate::default())->toBe(CvTemplate::Classic);
});

it('exposes a label, a description and a view for every template', function (): void {
    foreach (CvTemplate::cases() as $template) {
        expect($template->label())->not->toBe('')
            ->and($template->description())->not->toBe('')
            ->and($template->view())->toBe('pdf.cv.'.$template->value);
    }
});

it('stores the classic template on new profiles', function (): void {
    $user = User::factory()->create();
    $profile = CandidateProfile::factory()->create(['user_id' => $user->id]);

    expect($profile->refresh()->cv_template)->toBe(CvTemplate::Classic);
});

it('casts the stored template to the enum', function (): void {
    $user = User::factory()->create();
    $profile = CandidateProfile::factory()->create([
        'user_id' => $user->id,
        'cv_template' => CvTemplate::Modern,
    ]);

    expect($profile->refresh()->cv_template)->toBe(CvTemplate::Modern);
});

it('lists the catalog with the currently selected template', function (): void {
    actingCandidate(['cv_template' => CvTemplate::Compact]);

    $response = $this->getJson('/api/v1/me/profile/cv/templates')->assertOk();

    expect($response->json('data.selected'))->toBe('compact')
        ->and($response->json('data.templates'))->toHaveCount(count(CvTemplate::cases()))
        ->and(array_column($response->json('data.templates'), 'key'))
        ->toBe(array_column(CvTemplate::cases(), 'value'));

    foreach ($response->json('data.templates') as $template) {
        expect($template['name'])->not->toBe('')
            ->and($template['description'])->not->toBe('');
    }
});

it('saves the chosen template', function (): void {
    $user = actingCandidate();

    $this->putJson('/api/v1/me/profile/cv/template', ['template' => 'modern'])
        ->assertOk()
        ->assertJsonPath('data.selected', 'modern');

    expect($user->candidateProfile()->first()?->cv_template)->toBe(CvTemplate::Modern);
});

it('rejects a template that does not exist', function (): void {
    actingCandidate();

    $this->putJson('/api/v1/me/profile/cv/template', ['template' => 'papiro'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('template');
});

it('rejects a missing template', function (): void {
    actingCandidate();

    $this->putJson('/api/v1/me/profile/cv/template', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('template');
});

it('returns rendered HTML for every template preview', function (): void {
    actingCandidate(['summary' => 'Diseñadora de producto.']);

    foreach (CvTemplate::cases() as $template) {
        $response = $this->getJson('/api/v1/me/profile/cv/templates/'.$template->value.'/preview')
            ->assertOk()
            ->assertJsonPath('data.template', $template->value);

        expect($response->json('data.html'))
            ->toContain('<!DOCTYPE html>')
            ->toContain('Ana Pérez');
    }
});

it('404s on a preview for an unknown template', function (): void {
    actingCandidate();

    $this->getJson('/api/v1/me/profile/cv/templates/papiro/preview')->assertStatus(404);
});

it('falls back to sample content when the profile has no experience or studies', function (): void {
    actingCandidate(['summary' => null]);

    $response = $this->getJson('/api/v1/me/profile/cv/templates/classic/preview')->assertOk();

    // El nombre sigue siendo el real; el cuerpo es de ejemplo y así lo declara.
    expect($response->json('data.is_sample'))->toBeTrue()
        ->and($response->json('data.html'))
        ->toContain('Ana Pérez')
        ->toContain('Acá va tu resumen profesional');
});

it('keeps the real summary even when the rest is sample content', function (): void {
    actingCandidate(['summary' => 'Ocho años diseñando sistemas accesibles.']);

    $response = $this->getJson('/api/v1/me/profile/cv/templates/classic/preview')->assertOk();

    expect($response->json('data.is_sample'))->toBeTrue()
        ->and($response->json('data.html'))
        ->toContain('Ocho años diseñando sistemas accesibles.')
        ->not->toContain('Acá va tu resumen profesional');
});

it('uses real content instead of the sample once the profile has data', function (): void {
    $user = actingCandidate(['summary' => 'Ocho años diseñando sistemas accesibles.']);

    CandidateExperience::factory()->create([
        'candidate_profile_id' => $user->candidateProfile()->first()?->id,
        'position_title' => 'Lead Product Designer',
        'company_name' => 'Banorte Digital',
    ]);

    $response = $this->getJson('/api/v1/me/profile/cv/templates/classic/preview')->assertOk();

    expect($response->json('data.is_sample'))->toBeFalse()
        ->and($response->json('data.html'))
        ->toContain('Ocho años diseñando sistemas accesibles.')
        ->toContain('Lead Product Designer')
        ->not->toContain('Acá va tu resumen profesional');
});

it('keeps the template endpoints behind authentication', function (): void {
    $this->getJson('/api/v1/me/profile/cv/templates')->assertStatus(401);
    $this->getJson('/api/v1/me/profile/cv/templates/classic/preview')->assertStatus(401);
    $this->putJson('/api/v1/me/profile/cv/template', ['template' => 'modern'])->assertStatus(401);
});

it('keeps non-candidates out of the template endpoints', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me/profile/cv/templates')->assertForbidden();
    $this->putJson('/api/v1/me/profile/cv/template', ['template' => 'modern'])->assertForbidden();
});
