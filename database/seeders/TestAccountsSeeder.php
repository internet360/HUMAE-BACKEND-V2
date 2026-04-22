<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Crea usuarios de prueba en dev/staging con password conocido.
 * Idempotente: se puede correr varias veces sin duplicar.
 *
 * NO correrlo en producción.
 */
class TestAccountsSeeder extends Seeder
{
    private const PASSWORD = 'Password123';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('TestAccountsSeeder: saltado en producción.');

            return;
        }

        $this->createUser(
            email: 'admin@test.humae',
            name: 'Test Admin',
            role: UserRole::Admin,
        );

        $this->createUser(
            email: 'recruiter@test.humae',
            name: 'Test Recruiter',
            role: UserRole::Recruiter,
        );

        $companyUser = $this->createUser(
            email: 'company@test.humae',
            name: 'Test Company',
            role: UserRole::CompanyUser,
        );

        $company = Company::firstOrCreate(
            ['slug' => 'acme-corp'],
            [
                'legal_name' => 'Acme Corp S.A. de C.V.',
                'trade_name' => 'Acme Corp',
                'description' => 'Empresa de prueba generada por TestAccountsSeeder.',
                'website' => 'https://acme.test',
                'founded_year' => 2010,
                'contact_name' => 'Test Company',
                'contact_email' => 'company@test.humae',
                'contact_phone' => '+52 55 0000 0000',
                'status' => 'active',
                'is_verified' => false,
            ],
        );

        CompanyMember::firstOrCreate(
            [
                'company_id' => $company->id,
                'user_id' => $companyUser->id,
            ],
            [
                'role' => CompanyMemberRole::Owner,
                'is_primary_contact' => true,
                'accepted_at' => now(),
            ],
        );

        $this->command->info('TestAccountsSeeder: usuarios creados con password "'.self::PASSWORD.'".');
        $this->command->info('  - admin@test.humae     (admin)');
        $this->command->info('  - recruiter@test.humae (recruiter)');
        $this->command->info('  - company@test.humae   (company_user, owner de Acme Corp)');
    }

    private function createUser(string $email, string $name, UserRole $role): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'status' => 'active',
            ],
        );

        if (! $user->hasRole($role->value)) {
            $user->assignRole($role->value);
        }

        return $user;
    }
}
