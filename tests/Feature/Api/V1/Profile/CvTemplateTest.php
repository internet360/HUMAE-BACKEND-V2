<?php

declare(strict_types=1);

use App\Enums\CvTemplate;
use App\Models\CandidateProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

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
