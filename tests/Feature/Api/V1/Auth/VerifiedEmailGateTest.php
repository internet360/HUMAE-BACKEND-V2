<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Middleware\EnsureVerifiedEmail;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| F-17 — the registration token used to skip email verification
|--------------------------------------------------------------------------
|
| `AuthController::login()` refuses an account whose `email_verified_at` is
| null, but `POST /auth/register` used to hand back a working Sanctum token in
| the same response. That token never passed through login, so the only door
| watching for unverified accounts never saw it.
|
| This file pins both halves of the fix:
|
|   1. Registration issues no token at all, so there is no session to abuse.
|   2. `EnsureVerifiedEmail` sits in front of the authenticated surface, so a
|      token minted by any future path is still refused.
|
| ARCHITECTURE.md §8.1 orders the candidate flow `register → verify-email →
| /me/profile → /me/psychometrics → … → checkout`. Verification comes first.
|
*/

/**
 * Routes that must stay reachable without a verified email. Gating any of
 * these would lock the user out of the very flow that verifies them.
 */
const VERIFICATION_ESCAPE_HATCH = [
    'auth.logout',
    'auth.me',
    'auth.verification.resend',
];

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Builds an unverified user with the given role and a real Sanctum token, so
 * the probes exercise the token path rather than `Sanctum::actingAs`.
 *
 * @return array{0: User, 1: string}
 */
function unverifiedActor(UserRole $role): array
{
    $user = User::factory()->create(['email_verified_at' => null]);
    $user->assignRole($role->value);

    return [$user, $user->createToken('probe')->plainTextToken];
}

/*
|--------------------------------------------------------------------------
| The probe that found F-17, end to end
|--------------------------------------------------------------------------
*/

it('does not hand an unverified account a usable session at registration', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Candidato Sin Verificar',
        'email' => 'sinverificar@humae.test',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'accept_terms' => true,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.verification_required', true)
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.token_type');

    $user = User::where('email', 'sinverificar@humae.test')->firstOrFail();

    expect($user->email_verified_at)->toBeNull()
        ->and($user->tokens()->count())->toBe(0);

    // The credentials the client is left holding cannot open a session either.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'sinverificar@humae.test',
        'password' => 'Password123',
    ])
        ->assertForbidden()
        ->assertJsonPath('errors.code', ['email_unverified']);

    // Defence in depth: even a token minted out of band for that account is
    // refused, so a future token-issuing path cannot reopen the hole.
    $this->withToken($user->createToken('probe')->plainTextToken)
        ->getJson('/api/v1/me/profile')
        ->assertForbidden()
        ->assertJsonPath('errors.code', ['email_unverified']);
});

/*
|--------------------------------------------------------------------------
| The wall
|--------------------------------------------------------------------------
*/

it('refuses the authenticated surface to an unverified token holder', function (string $uri, UserRole $role): void {
    [, $token] = unverifiedActor($role);

    $this->withToken($token)
        ->getJson($uri)
        ->assertForbidden()
        // Same machine-readable code `AuthController::login()` already returns,
        // so the frontend branches on one contract instead of two.
        ->assertJsonPath('errors.code', ['email_unverified'])
        ->assertJsonPath('success', false);
})->with([
    'catalogs' => ['/api/v1/catalogs/skills', UserRole::Candidate],
    'candidate profile' => ['/api/v1/me/profile', UserRole::Candidate],
    'candidate psychometrics' => ['/api/v1/me/psychometrics/tests', UserRole::Candidate],
    'candidate membership' => ['/api/v1/me/membership', UserRole::Candidate],
    'candidate payments' => ['/api/v1/me/payments', UserRole::Candidate],
    'notifications' => ['/api/v1/me/notifications', UserRole::Candidate],
    'vacancies' => ['/api/v1/vacancies', UserRole::Recruiter],
    'directory' => ['/api/v1/directory/candidates', UserRole::Recruiter],
    'interviews' => ['/api/v1/interviews', UserRole::Recruiter],
    'own company' => ['/api/v1/me/company', UserRole::CompanyUser],
    'admin reports' => ['/api/v1/admin/reports/candidates-registered', UserRole::Admin],
    'admin users' => ['/api/v1/admin/users', UserRole::Admin],
    'admin catalogs' => ['/api/v1/admin/catalogs/skills', UserRole::Admin],
]);

it('gates every authenticated route except the verification escape hatch', function (): void {
    $ungated = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->reject(fn ($route): bool => in_array($route->getName(), VERIFICATION_ESCAPE_HATCH, true))
        ->reject(fn ($route): bool => in_array(EnsureVerifiedEmail::class, $route->gatherMiddleware(), true))
        ->map(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->values()
        ->all();

    // A new authenticated route inherits the gate from its group. If one shows
    // up here, it was registered outside every gated group — decide whether it
    // belongs in VERIFICATION_ESCAPE_HATCH or needs the middleware.
    expect($ungated)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The escape hatch
|--------------------------------------------------------------------------
*/

it('keeps the verification escape hatch open for an unverified user', function (): void {
    [$user, $token] = unverifiedActor(UserRole::Candidate);

    $this->getJson('/api/v1/health')->assertOk();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/auth/resend-verification')
        ->assertOk();

    $this->getJson(sprintf(
        '/api/v1/auth/verify-email/%d/%s',
        $user->id,
        sha1((string) $user->getEmailForVerification()),
    ))->assertOk();

    $this->postJson('/api/v1/auth/verify-email/resend', ['email' => $user->email])
        ->assertOk();

    // Logout last: it revokes the token the probes above depend on.
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertStatus(204);
});

it('leaves the public auth surface free of the gate', function (): void {
    // These carry no `auth:sanctum`, so the middleware would answer 401 to
    // everyone and make the account unrecoverable. Pinned structurally because
    // the failure mode is silent until a real user is locked out.
    $public = [
        'health',
        'auth.register',
        'auth.register.recruiter',
        'auth.login',
        'auth.password.forgot',
        'auth.password.reset',
        'auth.verification.verify',
        'auth.verification.resend-public',
        'auth.invitation.show',
        'auth.invitation.accept',
        'webhooks.stripe',
    ];

    $gated = [];

    foreach (array_merge($public, VERIFICATION_ESCAPE_HATCH) as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull();

        if (in_array(EnsureVerifiedEmail::class, $route->gatherMiddleware(), true)) {
            $gated[] = $name;
        }
    }

    expect($gated)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Invited accounts
|--------------------------------------------------------------------------
*/

it('lets an invited recruiter through the gate because accepting verifies the email', function (): void {
    $plainToken = Str::random(64);

    // Mirrors what `Admin\UserController::store()` writes for an invite: no
    // password the user knows, no verified email, one hashed invitation token.
    $invited = User::factory()->create([
        'email' => 'invitado@humae.test',
        'email_verified_at' => null,
        'status' => 'invited',
        'password' => Hash::make(Str::random(40)),
    ]);
    $invited->assignRole(UserRole::Recruiter->value);
    $invited->forceFill([
        'invitation_token' => hash('sha256', $plainToken),
        'invitation_expires_at' => now()->addDays(7),
        'invitation_accepted_at' => null,
    ])->save();

    $response = $this->postJson('/api/v1/auth/invitation/accept', [
        'token' => $plainToken,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertOk();

    // `InvitationController::accept()` sets `email_verified_at` on acceptance,
    // so the token it issues clears the gate on its first call.
    expect($invited->refresh()->email_verified_at)->not->toBeNull();

    $this->withToken((string) $response->json('data.token'))
        ->getJson('/api/v1/vacancies')
        ->assertOk();
});

it('keeps an already verified invited user verified on acceptance', function (): void {
    $plainToken = Str::random(64);
    $verifiedAt = now()->subMonth();

    $invited = User::factory()->create([
        'email' => 'invitado-verificado@humae.test',
        'email_verified_at' => $verifiedAt,
        'status' => 'invited',
    ]);
    $invited->assignRole(UserRole::CompanyUser->value);
    $invited->forceFill([
        'invitation_token' => hash('sha256', $plainToken),
        'invitation_expires_at' => now()->addDays(7),
        'invitation_accepted_at' => null,
    ])->save();

    $this->postJson('/api/v1/auth/invitation/accept', [
        'token' => $plainToken,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertOk();

    expect($invited->refresh()->email_verified_at?->toIso8601String())
        ->toBe($verifiedAt->toIso8601String());
});
