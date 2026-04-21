<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Seeder;

class StateSeeder extends Seeder
{
    public function run(): void
    {
        $mexico = Country::where('code', 'MX')->first();

        if ($mexico === null) {
            return;
        }

        $mxStates = [
            ['code' => 'AGS', 'name' => 'Aguascalientes'],
            ['code' => 'BCN', 'name' => 'Baja California'],
            ['code' => 'BCS', 'name' => 'Baja California Sur'],
            ['code' => 'CAM', 'name' => 'Campeche'],
            ['code' => 'CHP', 'name' => 'Chiapas'],
            ['code' => 'CHH', 'name' => 'Chihuahua'],
            ['code' => 'CMX', 'name' => 'Ciudad de México'],
            ['code' => 'COA', 'name' => 'Coahuila'],
            ['code' => 'COL', 'name' => 'Colima'],
            ['code' => 'DUR', 'name' => 'Durango'],
            ['code' => 'GUA', 'name' => 'Guanajuato'],
            ['code' => 'GRO', 'name' => 'Guerrero'],
            ['code' => 'HID', 'name' => 'Hidalgo'],
            ['code' => 'JAL', 'name' => 'Jalisco'],
            ['code' => 'MEX', 'name' => 'Estado de México'],
            ['code' => 'MIC', 'name' => 'Michoacán'],
            ['code' => 'MOR', 'name' => 'Morelos'],
            ['code' => 'NAY', 'name' => 'Nayarit'],
            ['code' => 'NLE', 'name' => 'Nuevo León'],
            ['code' => 'OAX', 'name' => 'Oaxaca'],
            ['code' => 'PUE', 'name' => 'Puebla'],
            ['code' => 'QUE', 'name' => 'Querétaro'],
            ['code' => 'ROO', 'name' => 'Quintana Roo'],
            ['code' => 'SLP', 'name' => 'San Luis Potosí'],
            ['code' => 'SIN', 'name' => 'Sinaloa'],
            ['code' => 'SON', 'name' => 'Sonora'],
            ['code' => 'TAB', 'name' => 'Tabasco'],
            ['code' => 'TAM', 'name' => 'Tamaulipas'],
            ['code' => 'TLA', 'name' => 'Tlaxcala'],
            ['code' => 'VER', 'name' => 'Veracruz'],
            ['code' => 'YUC', 'name' => 'Yucatán'],
            ['code' => 'ZAC', 'name' => 'Zacatecas'],
        ];

        foreach ($mxStates as $i => $data) {
            State::updateOrCreate(
                ['country_id' => $mexico->id, 'code' => $data['code']],
                [
                    'name' => $data['name'],
                    'sort_order' => $i + 1,
                    'is_active' => true,
                ]
            );
        }
    }
}
