<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContactSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactSubmission>
 */
class ContactSubmissionFactory extends Factory
{
    protected $model = ContactSubmission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'contact',
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'company' => fake()->company(),
            'subject' => fake()->sentence(5),
            'message' => fake()->paragraph(),
            'source' => 'landing',
            'status' => 'new',
        ];
    }
}
