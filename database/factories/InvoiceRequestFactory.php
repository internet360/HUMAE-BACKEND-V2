<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvoiceRequestStatus;
use App\Models\InvoiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceRequest>
 */
class InvoiceRequestFactory extends Factory
{
    protected $model = InvoiceRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => InvoiceRequestStatus::Requested,
            'rfc' => 'XXXX010101AAA',
            'legal_name' => fake()->name(),
            'tax_regime' => '612',
            'postal_code' => '06600',
            'cfdi_use' => 'G03',
            'email' => fake()->safeEmail(),
        ];
    }
}
