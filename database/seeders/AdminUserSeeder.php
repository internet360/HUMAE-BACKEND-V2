<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@humae.com.mx'],
            [
                'name' => 'HUMAE Admin',
                'password' => Hash::make('humae_admin_2026'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        if (! $admin->hasRole(UserRole::Admin->value)) {
            $admin->assignRole(UserRole::Admin->value);
        }
    }
}
