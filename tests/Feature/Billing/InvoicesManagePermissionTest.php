<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function invoicesManageMigration(): Migration
{
    return require database_path('migrations/2026_10_07_120000_add_invoices_manage_permission.php');
}

it('is created and granted to admin by the seeder only', function (): void {
    Permission::where('name', 'invoices.manage')->delete();
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::findByName(UserRole::Admin->value)->hasPermissionTo('invoices.manage'))->toBeTrue();

    foreach ([UserRole::Candidate, UserRole::Recruiter, UserRole::CompanyUser] as $role) {
        expect(Role::findByName($role->value)->hasPermissionTo('invoices.manage'))->toBeFalse();
    }
});

it('is created by the data migration without running the seeder', function (): void {
    Permission::where('name', 'invoices.manage')->delete();
    Role::where('name', UserRole::Admin->value)->delete();

    invoicesManageMigration()->up();

    expect(Role::findByName(UserRole::Admin->value)->hasPermissionTo('invoices.manage'))->toBeTrue();
});

it('is idempotent when run twice or after the seeder', function (): void {
    Permission::where('name', 'invoices.manage')->delete();

    invoicesManageMigration()->up();
    invoicesManageMigration()->up();
    $this->seed(RolesAndPermissionsSeeder::class);
    invoicesManageMigration()->up();

    expect(Permission::where('name', 'invoices.manage')->count())->toBe(1)
        ->and(Role::findByName(UserRole::Admin->value)->permissions()->where('name', 'invoices.manage')->count())->toBe(1);
});

it('removes the permission on rollback', function (): void {
    invoicesManageMigration()->up();
    invoicesManageMigration()->down();

    expect(Permission::where('name', 'invoices.manage')->exists())->toBeFalse();
});
