<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Spatie grants vs ARCHITECTURE.md §6
|--------------------------------------------------------------------------
|
| Authorization runs through Policy method names today, so the permission
| table looks inert. It is not: spatie/laravel-permission registers a
| `Gate::before` that returns true as soon as the ability name matches a
| permission the user holds. The Policy never runs. A grant that §6 denies is
| therefore a Policy that will switch itself off the first time somebody writes
| `$user->can('directory.view-full')`.
|
| This test is the durable guard: it pins the ❌ cells of §6 as permissions the
| role must NOT hold, and proves the gate stays shut end to end.
|
*/

/**
 * The ❌ cells of ARCHITECTURE.md §6, expressed as permission names.
 *
 * "—" cells (not applicable to the role) are deliberately absent: §6 marks
 * them as out of scope, not as denied.
 *
 * @var array<string, list<string>>
 */
const SECTION_6_DENIED_PERMISSIONS = [
    'candidate' => [
        // Ver directorio / expediente / CV ajeno / favoritos
        'directory.view',
        'directory.view-full',
        'directory.favorite',
        'cv.download-any',
        // Crear, aprobar y cerrar vacantes
        'vacancies.create',
        'vacancies.publish',
        'vacancies.close',
        'vacancies.update-any',
        'vacancies.view-any',
        // Pipeline
        'assignments.create',
        'assignments.update',
        'assignments.view-any',
        'assignments.notes.create',
        // Entrevistas: el candidato confirma, no programa
        'interviews.schedule',
        'interviews.view-any',
        // Empresas
        'companies.create',
        'companies.update-any',
        'companies.delete',
        // Administración
        'catalogs.manage',
        'psychometric.manage',
        'settings.manage',
        'users.manage',
        'impersonate.users',
        // Reportes
        'reports.view-own',
        'reports.view-any',
    ],
    'recruiter' => [
        // §6: CRUD catálogos ❌, plantillas PDF ❌, pruebas psicométricas ❌
        'catalogs.manage',
        'psychometric.manage',
        'settings.manage',
        // §5.10 deja la gestión de usuarios y la impersonación en admin
        'users.manage',
        'impersonate.users',
        // §6: «Ver reportes — Reclutador ✅ (sus procesos)», no todos
        'reports.view-any',
    ],
    'company_user' => [
        // §6: «Ver directorio de candidatos — Empresa cliente: ❌»
        'directory.view',
        // §6: «Ver expediente completo de candidato — Empresa cliente: ❌»
        'directory.view-full',
        // §6: «Marcar favoritos — Empresa cliente: ❌»
        'directory.favorite',
        // §6: «Descargar CV de cualquier candidato — Empresa cliente: ❌»
        'cv.download-any',
        // §6: «Aprobar / activar vacante — Empresa cliente: ❌»
        'vacancies.publish',
        // §6: «Asignar candidatos a vacante — Empresa cliente: ❌»
        'assignments.create',
        // §5.7 deja el pipeline en recruiter/admin; la empresa sólo decide finalista
        'assignments.update',
        'assignments.view-any',
        // Alcance global sobre vacantes y empresas ajenas
        'vacancies.view-any',
        'vacancies.update-any',
        'companies.create',
        'companies.update-any',
        'companies.delete',
        // Entrevistas ajenas
        'interviews.view-any',
        // §6: CRUD catálogos ❌, plantillas PDF ❌, pruebas psicométricas ❌
        'catalogs.manage',
        'psychometric.manage',
        'settings.manage',
        'users.manage',
        'impersonate.users',
        // §6: «Ver reportes — Empresa cliente ✅ (sus vacantes)», no todos
        'reports.view-any',
    ],
];

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('does not grant any role a permission that §6 denies it', function (string $role, array $denied): void {
    $granted = Role::findByName($role)->permissions->pluck('name')->all();

    $violations = array_values(array_intersect($granted, $denied));

    expect($violations)->toBe([]);
})->with(function (): array {
    $cases = [];

    foreach (SECTION_6_DENIED_PERMISSIONS as $role => $denied) {
        $cases[$role] = [$role, $denied];
    }

    return $cases;
});

it('keeps the client company out of the talent base through the Spatie gate', function (string $ability): void {
    $companyUser = User::factory()->create();
    $companyUser->assignRole(UserRole::CompanyUser->value);

    // `Gate::before` from spatie/laravel-permission answers before any Policy.
    // If the permission is granted, this returns true and CandidateProfilePolicy
    // is never consulted.
    expect($companyUser->can($ability))->toBeFalse();
})->with([
    'directory.view',
    'directory.view-full',
    'directory.favorite',
    'cv.download-any',
    'assignments.create',
    'assignments.update',
    'vacancies.publish',
]);

it('still grants the client company everything §6 says it owns', function (): void {
    $granted = Role::findByName(UserRole::CompanyUser->value)->permissions->pluck('name')->all();

    expect($granted)->toContain(
        'companies.view-own',
        'companies.update-own',
        'vacancies.view-own',
        'vacancies.create',
        'vacancies.update-own',
        'vacancies.close',
        'assignments.view-own',
        'assignments.notes.create',
        'interviews.confirm',
        'interviews.view-own',
        'interviews.schedule',
        'reports.view-own',
    );
});

it('grants the admin role every permission', function (): void {
    $all = Permission::pluck('name')->sort()->values()->all();
    $granted = Role::findByName(UserRole::Admin->value)
        ->permissions->pluck('name')->sort()->values()->all();

    expect($granted)->toBe($all);
});
