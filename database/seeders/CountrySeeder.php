<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            ['code' => 'MX', 'name' => 'México', 'phone_code' => '+52', 'sort_order' => 1],
            ['code' => 'US', 'name' => 'Estados Unidos', 'phone_code' => '+1', 'sort_order' => 2],
            ['code' => 'CA', 'name' => 'Canadá', 'phone_code' => '+1', 'sort_order' => 3],
            ['code' => 'ES', 'name' => 'España', 'phone_code' => '+34', 'sort_order' => 4],
            ['code' => 'AR', 'name' => 'Argentina', 'phone_code' => '+54', 'sort_order' => 5],
            ['code' => 'CO', 'name' => 'Colombia', 'phone_code' => '+57', 'sort_order' => 6],
            ['code' => 'CL', 'name' => 'Chile', 'phone_code' => '+56', 'sort_order' => 7],
            ['code' => 'PE', 'name' => 'Perú', 'phone_code' => '+51', 'sort_order' => 8],
            ['code' => 'BR', 'name' => 'Brasil', 'phone_code' => '+55', 'sort_order' => 9],
        ];

        foreach ($countries as $data) {
            Country::updateOrCreate(['code' => $data['code']], $data + ['is_active' => true]);
        }
    }
}
