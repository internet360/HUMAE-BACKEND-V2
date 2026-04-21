<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CompanyMemberRole;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyMember>
 */
class CompanyMemberFactory extends Factory
{
    protected $model = CompanyMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'role' => CompanyMemberRole::Viewer,
            'job_title' => fake()->jobTitle(),
            'is_primary_contact' => false,
            'accepted_at' => now(),
        ];
    }
}
