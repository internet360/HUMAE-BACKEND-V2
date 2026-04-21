<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('returns a PDF with the correct headers', function (): void {
    $user = User::factory()->create(['name' => 'Ana Pérez']);
    $user->assignRole(UserRole::Candidate->value);
    CandidateProfile::factory()->create([
        'user_id' => $user->id,
        'first_name' => 'Ana',
        'last_name' => 'Pérez',
        'headline' => 'UX Designer con 5 años de experiencia',
        'summary' => 'Diseñadora apasionada por la accesibilidad.',
    ]);

    Sanctum::actingAs($user);

    $response = $this->get('/api/v1/me/profile/cv.pdf');

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $disposition = $response->headers->get('Content-Disposition') ?? '';
    expect($disposition)->toContain('attachment')
        ->and($disposition)->toContain('cv-humae-ana-perez.pdf');

    // El cuerpo debe empezar con el magic number de un PDF
    expect(substr($response->getContent() ?: '', 0, 4))->toBe('%PDF');
});

it('rejects unauthenticated CV downloads', function (): void {
    $this->getJson('/api/v1/me/profile/cv.pdf')->assertStatus(401);
});
