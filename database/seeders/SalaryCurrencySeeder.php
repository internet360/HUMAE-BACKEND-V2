<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SalaryCurrency;
use Illuminate\Database\Seeder;

class SalaryCurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            ['code' => 'MXN', 'name' => 'Peso mexicano', 'symbol' => '$', 'sort_order' => 1],
            ['code' => 'USD', 'name' => 'Dólar estadounidense', 'symbol' => 'US$', 'sort_order' => 2],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'sort_order' => 3],
        ];

        foreach ($currencies as $data) {
            SalaryCurrency::updateOrCreate(['code' => $data['code']], $data + ['is_active' => true]);
        }
    }
}
