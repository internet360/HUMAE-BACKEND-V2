<?php

declare(strict_types=1);

use App\Enums\CvTemplate;
use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\User;
use App\Services\CvGenerationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\View;
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

it('escapes candidate-supplied contact data in every CV template', function (): void {
    // contact_phone es texto libre sin validación de formato, así que es el
    // campo más directo para inyectar marcado en la línea de contacto.
    $payload = '<img src=x onerror=alert(1)>';

    $user = User::factory()->create(['name' => 'Ana Pérez']);
    CandidateProfile::factory()->create([
        'user_id' => $user->id,
        'first_name' => 'Ana',
        'last_name' => 'Pérez',
        'contact_phone' => $payload,
    ]);

    $cv = app(CvGenerationService::class)->buildViewData($user);

    foreach (CvTemplate::cases() as $template) {
        $html = View::make($template->view(), ['cv' => $cv])->render();

        expect($html)->not->toContain($payload);
    }

    // Y el dato sí se imprime: si no, lo de arriba pasaría por vacío.
    expect(View::make(CvTemplate::Classic->view(), ['cv' => $cv])->render())
        ->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

it('inlines the HUMAE logo as a data URI so it renders outside DomPDF', function (): void {
    $user = User::factory()->create(['name' => 'Ana Pérez']);
    CandidateProfile::factory()->create(['user_id' => $user->id]);

    $cv = app(CvGenerationService::class)->buildViewData($user);

    expect($cv->logoSrc)->toStartWith('data:image/png;base64,');

    $html = View::make(CvTemplate::Classic->view(), ['cv' => $cv])->render();

    expect($html)->toContain('data:image/png;base64,')
        ->and($html)->not->toContain(resource_path('views/pdf/humae-logo.png'));
});

it('renders a PDF for every template', function (): void {
    $user = User::factory()->create(['name' => 'Ana Pérez']);
    $user->assignRole(UserRole::Candidate->value);
    $profile = CandidateProfile::factory()->create([
        'user_id' => $user->id,
        'first_name' => 'Ana',
        'last_name' => 'Pérez',
        'headline' => 'UX Designer',
        'summary' => 'Diseñadora apasionada por la accesibilidad.',
    ]);

    Sanctum::actingAs($user);

    foreach (CvTemplate::cases() as $template) {
        $profile->update(['cv_template' => $template]);

        $response = $this->get('/api/v1/me/profile/cv.pdf');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(substr($response->getContent() ?: '', 0, 4))->toBe('%PDF');
    }
});
