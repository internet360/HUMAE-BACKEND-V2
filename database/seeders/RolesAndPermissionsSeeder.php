<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const PERMISSIONS = [
        // Perfil propio
        'profile.view-own',
        'profile.update-own',
        'profile.delete-own',

        // Membresía
        'membership.subscribe',
        'membership.cancel',

        // Psicométricos
        'psychometric.take',
        'psychometric.view-own-results',

        // CV
        'cv.download-own',
        'cv.download-any',

        // Directorio de candidatos (HUMAE interno)
        'directory.view',
        'directory.view-full',
        'directory.favorite',

        // Empresas
        'companies.view-own',
        'companies.update-own',
        'companies.create',
        'companies.update-any',
        'companies.delete',

        // Vacantes
        'vacancies.view-own',
        'vacancies.view-assigned',
        'vacancies.view-any',
        'vacancies.create',
        'vacancies.update-own',
        'vacancies.update-any',
        'vacancies.publish',
        'vacancies.close',
        'vacancies.delete',

        // Pipeline / asignaciones
        'assignments.create',
        'assignments.update',
        'assignments.view-own',
        'assignments.view-any',
        'assignments.notes.create',

        // Entrevistas
        'interviews.schedule',
        'interviews.confirm',
        'interviews.reschedule',
        'interviews.cancel',
        'interviews.view-own',
        'interviews.view-any',

        // Catálogos (admin)
        'catalogs.manage',

        // Pruebas psicométricas (admin)
        'psychometric.manage',

        // Reportes
        'reports.view-own',
        'reports.view-any',

        // Admin
        'users.manage',
        'settings.manage',
        'impersonate.users',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'candidate' => [
            'profile.view-own',
            'profile.update-own',
            'profile.delete-own',
            'membership.subscribe',
            'membership.cancel',
            'psychometric.take',
            'psychometric.view-own-results',
            'cv.download-own',
            'interviews.confirm',
            'interviews.view-own',
        ],
        'recruiter' => [
            'directory.view',
            'directory.view-full',
            'directory.favorite',
            'cv.download-any',
            'companies.view-own',
            'vacancies.view-assigned',
            'vacancies.view-any',
            'vacancies.create',
            'vacancies.update-own',
            'vacancies.publish',
            'vacancies.close',
            'assignments.create',
            'assignments.update',
            'assignments.view-any',
            'assignments.notes.create',
            'interviews.schedule',
            'interviews.reschedule',
            'interviews.cancel',
            'interviews.view-any',
            'reports.view-own',
        ],
        'company_user' => [
            'companies.view-own',
            'companies.update-own',
            'vacancies.view-own',
            'vacancies.create',
            'vacancies.update-own',
            'assignments.view-own',
            'interviews.confirm',
            'interviews.view-own',
            'reports.view-own',
        ],
        'admin' => [
            // Admin obtiene TODOS los permisos (asignados abajo vía syncPermissions)
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (UserRole::values() as $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            $permissions = $roleName === UserRole::Admin->value
                ? self::PERMISSIONS
                : (self::ROLE_PERMISSIONS[$roleName] ?? []);

            $role->syncPermissions($permissions);
        }

        Artisan::call('cache:clear');
    }
}
