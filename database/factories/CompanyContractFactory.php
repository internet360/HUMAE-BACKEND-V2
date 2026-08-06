<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyContract;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompanyContract>
 */
class CompanyContractFactory extends Factory
{
    protected $model = CompanyContract::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $signedAt = fake()->dateTimeBetween('-1 year');
        $hash = hash('sha256', Str::random(40));

        return [
            'company_id' => Company::factory(),
            'signed_by_user_id' => User::factory(),
            'folio' => 'HUMAE-CTR-'.$signedAt->format('Y').'-'.str_pad(
                (string) fake()->unique()->numberBetween(1, 999999),
                6,
                '0',
                STR_PAD_LEFT,
            ),
            'signer_title' => fake()->jobTitle(),
            'terms' => [
                'version' => '2026.1',
                'provider_name' => 'Humae Consultoría de RH',
                'fee_kind' => 'percentage_annual_gross',
                'fee_value' => 12.0,
                'fee_amount_words' => null,
                'payment_days' => 5,
                'payment_day_kind' => 'habiles',
                'warranty_days' => 90,
                'city' => 'Querétaro, Querétaro',
                'jurisdiction' => 'la ciudad de Querétaro, Querétaro, Estados Unidos Mexicanos',
                'signatory' => ['name' => 'Apoderado HUMAE', 'title' => 'Representante Legal'],
            ],
            'signature_path' => 'contracts/1/signature/'.Str::random(40).'.png',
            'identity_path' => 'contracts/1/identity/'.Str::random(40).'.jpg',
            'selfie_path' => 'contracts/1/selfie/'.Str::random(40).'.jpg',
            'pdf_path' => 'contracts/1/contract.pdf',
            'pdf_hash' => $hash,
            'timestamp_path' => 'contracts/1/'.$hash.'.asn1',
            'timestamped_at' => $signedAt,
            'signed_at' => $signedAt,
            'terms_accepted_at' => $signedAt,
            'privacy_accepted_at' => $signedAt,
            'signed_ip' => fake()->ipv4(),
            'signed_user_agent' => fake()->userAgent(),
        ];
    }

    /**
     * Contrato firmado cuya constancia NOM-151 no llegó (CINCEL caído).
     */
    public function withoutTimestamp(): self
    {
        return $this->state(fn (): array => [
            'timestamp_path' => null,
            'timestamped_at' => null,
        ]);
    }
}
