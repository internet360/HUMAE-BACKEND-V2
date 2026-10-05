<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

it('exposes Content-Disposition on a cross-origin download so the frontend can read the filename', function (): void {
    config(['cors.allowed_origins' => ['https://app.humae.test']]);
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create(['name' => 'Ana Pérez']);
    $user->assignRole(UserRole::Candidate->value);
    CandidateProfile::factory()->create(['user_id' => $user->id, 'first_name' => 'Ana', 'last_name' => 'Pérez']);
    Sanctum::actingAs($user);

    $response = $this->get('/api/v1/me/profile/cv.pdf', ['Origin' => 'https://app.humae.test'])->assertOk();

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.humae.test')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and(strtolower((string) $response->headers->get('Access-Control-Expose-Headers')))->toContain('content-disposition');
});
