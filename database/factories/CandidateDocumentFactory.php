<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateDocument>
 */
class CandidateDocumentFactory extends Factory
{
    protected $model = CandidateDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_profile_id' => CandidateProfile::factory(),
            'type' => fake()->randomElement(DocumentType::cases()),
            'title' => fake()->word().'-'.fake()->word(),
            'file_url' => fake()->url(),
            'file_provider' => 'local',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => fake()->numberBetween(10000, 5000000),
            'is_internal' => false,
            'uploaded_at' => now(),
        ];
    }
}
