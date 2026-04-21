<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CompanySize;
use App\Models\Industry;
use App\Models\OwnershipType;
use Illuminate\Database\Seeder;

class CompanyTaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $industries = [
            ['code' => 'tech', 'name' => 'Tecnología / IT'],
            ['code' => 'finance', 'name' => 'Finanzas y banca'],
            ['code' => 'retail', 'name' => 'Comercio / Retail'],
            ['code' => 'manufacturing', 'name' => 'Manufactura / Industria'],
            ['code' => 'healthcare', 'name' => 'Salud / Farmacéutica'],
            ['code' => 'education', 'name' => 'Educación'],
            ['code' => 'construction', 'name' => 'Construcción / Inmobiliaria'],
            ['code' => 'logistics', 'name' => 'Logística y transporte'],
            ['code' => 'hospitality', 'name' => 'Hospitalidad / Turismo'],
            ['code' => 'energy', 'name' => 'Energía'],
            ['code' => 'agriculture', 'name' => 'Agricultura / Ganadería'],
            ['code' => 'consulting', 'name' => 'Consultoría'],
            ['code' => 'legal', 'name' => 'Servicios legales'],
            ['code' => 'marketing', 'name' => 'Marketing y publicidad'],
            ['code' => 'media', 'name' => 'Medios y entretenimiento'],
            ['code' => 'telecom', 'name' => 'Telecomunicaciones'],
            ['code' => 'nonprofit', 'name' => 'Organización sin fines de lucro'],
            ['code' => 'government', 'name' => 'Gobierno y sector público'],
            ['code' => 'other', 'name' => 'Otro'],
        ];

        foreach ($industries as $i => $data) {
            Industry::updateOrCreate(
                ['code' => $data['code']],
                $data + ['sort_order' => $i + 1, 'is_active' => true]
            );
        }

        $sizes = [
            ['code' => 'micro', 'name' => '1–10 empleados'],
            ['code' => 'small', 'name' => '11–50 empleados'],
            ['code' => 'medium', 'name' => '51–250 empleados'],
            ['code' => 'large', 'name' => '251–1000 empleados'],
            ['code' => 'enterprise', 'name' => 'Más de 1000 empleados'],
        ];

        foreach ($sizes as $i => $data) {
            CompanySize::updateOrCreate(
                ['code' => $data['code']],
                $data + ['sort_order' => $i + 1, 'is_active' => true]
            );
        }

        $ownerships = [
            ['code' => 'private', 'name' => 'Privada'],
            ['code' => 'public', 'name' => 'Pública / Gubernamental'],
            ['code' => 'listed', 'name' => 'Cotizada en bolsa'],
            ['code' => 'family', 'name' => 'Empresa familiar'],
            ['code' => 'startup', 'name' => 'Startup'],
            ['code' => 'nonprofit', 'name' => 'Sin fines de lucro'],
        ];

        foreach ($ownerships as $i => $data) {
            OwnershipType::updateOrCreate(
                ['code' => $data['code']],
                $data + ['sort_order' => $i + 1, 'is_active' => true]
            );
        }
    }
}
