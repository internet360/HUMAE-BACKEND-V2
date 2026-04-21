<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\SalaryCurrency;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 100, 2000);

        return [
            'user_id' => User::factory(),
            'status' => PaymentStatus::Succeeded,
            'salary_currency_id' => SalaryCurrency::factory(),
            'amount' => $amount,
            'fee_amount' => 0,
            'net_amount' => $amount,
            'provider' => 'stripe',
            'paid_at' => now(),
        ];
    }
}
