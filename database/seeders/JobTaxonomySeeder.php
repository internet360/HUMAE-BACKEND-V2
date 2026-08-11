<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CareerLevel;
use App\Models\DegreeLevel;
use App\Models\FunctionalArea;
use App\Models\VacancyCategory;
use App\Models\VacancyShift;
use App\Models\VacancyTag;
use App\Models\VacancyType;
use Illuminate\Database\Seeder;

class JobTaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $this->seedList(CareerLevel::class, [
            ['code' => 'intern', 'name' => 'Becario / Practicante'],
            ['code' => 'entry', 'name' => 'Nivel inicial (sin experiencia)'],
            ['code' => 'junior', 'name' => 'Junior (1–2 años)'],
            ['code' => 'mid', 'name' => 'Semi-Senior (3–5 años)'],
            ['code' => 'senior', 'name' => 'Senior (5+ años)'],
            ['code' => 'lead', 'name' => 'Líder / Jefatura'],
            ['code' => 'manager', 'name' => 'Gerencia'],
            ['code' => 'director', 'name' => 'Dirección'],
            ['code' => 'executive', 'name' => 'C-Level / Ejecutivo'],
        ]);

        $this->seedList(DegreeLevel::class, [
            ['code' => 'none', 'name' => 'Sin estudios formales'],
            ['code' => 'secondary', 'name' => 'Secundaria'],
            ['code' => 'highschool', 'name' => 'Bachillerato / Preparatoria'],
            ['code' => 'technical', 'name' => 'Técnico superior'],
            ['code' => 'bachelor', 'name' => 'Licenciatura'],
            ['code' => 'master', 'name' => 'Maestría'],
            ['code' => 'phd', 'name' => 'Doctorado'],
        ]);

        // Áreas del PDF cosasfaltanteshumae.pdf (orden y nombres canónicos del cliente).
        // Las 15 primeras son del listado explícito; las restantes son áreas
        // adicionales que ya existían en el sistema y se conservan para no romper
        // perfiles/vacantes ya guardados.
        $this->seedList(FunctionalArea::class, [
            ['code' => 'manufacturing', 'name' => 'Producción'],
            ['code' => 'quality', 'name' => 'Calidad'],
            ['code' => 'maintenance', 'name' => 'Mantenimiento'],
            ['code' => 'logistics', 'name' => 'Logística'],
            ['code' => 'hr', 'name' => 'Recursos Humanos'],
            ['code' => 'admin', 'name' => 'Administración'],
            ['code' => 'industrial_safety', 'name' => 'Seguridad Industrial'],
            ['code' => 'warehouse', 'name' => 'Almacén'],
            ['code' => 'sales', 'name' => 'Ventas'],
            ['code' => 'engineering', 'name' => 'Ingeniería'],
            ['code' => 'purchasing', 'name' => 'Compras'],
            ['code' => 'it_systems', 'name' => 'Sistemas'],
            ['code' => 'customer', 'name' => 'Atención al cliente'],
            ['code' => 'operations', 'name' => 'Operación'],
            ['code' => 'finance', 'name' => 'Finanzas'],
            // Áreas auxiliares (no del PDF pero útiles para perfiles tech / corporativos)
            ['code' => 'product', 'name' => 'Producto'],
            ['code' => 'design', 'name' => 'Diseño'],
            ['code' => 'data', 'name' => 'Datos / Analítica'],
            ['code' => 'marketing', 'name' => 'Marketing'],
            ['code' => 'legal', 'name' => 'Legal / Compliance'],
            // Área macro 7 de "PUESTOS DE BUSQUEDA.docx", que no existía en el
            // catálogo original del PDF.
            [
                'code' => 'health',
                'name' => 'Salud, Laboratorio y Biotecnología',
                'description' => 'Un sector con altas regulaciones donde la formación académica suele estar muy estipulada legalmente.',
            ],
            ['code' => 'other', 'name' => 'Otra'],
        ]);

        $this->seedList(VacancyCategory::class, [
            ['code' => 'permanent', 'name' => 'Contratación permanente'],
            ['code' => 'temporary', 'name' => 'Temporal'],
            ['code' => 'project', 'name' => 'Por proyecto'],
            ['code' => 'internship', 'name' => 'Prácticas'],
            ['code' => 'executive_search', 'name' => 'Executive Search'],
        ]);

        $this->seedList(VacancyType::class, [
            ['code' => 'full_time', 'name' => 'Tiempo completo'],
            ['code' => 'part_time', 'name' => 'Medio tiempo'],
            ['code' => 'contract', 'name' => 'Contrato por honorarios'],
            ['code' => 'freelance', 'name' => 'Freelance'],
            ['code' => 'internship', 'name' => 'Becario'],
        ]);

        $this->seedList(VacancyShift::class, [
            ['code' => 'morning', 'name' => 'Matutino'],
            ['code' => 'afternoon', 'name' => 'Vespertino'],
            ['code' => 'night', 'name' => 'Nocturno'],
            ['code' => 'rotating', 'name' => 'Rotativo'],
            ['code' => 'flexible', 'name' => 'Horario flexible'],
            ['code' => 'weekends', 'name' => 'Fines de semana'],
        ]);

        $this->seedList(VacancyTag::class, [
            ['code' => 'remote', 'name' => 'Remoto'],
            ['code' => 'hybrid', 'name' => 'Híbrido'],
            ['code' => 'onsite', 'name' => 'Presencial'],
            ['code' => 'urgent', 'name' => 'Urgente'],
            ['code' => 'confidential', 'name' => 'Confidencial'],
            ['code' => 'diversity', 'name' => 'Diversidad e inclusión'],
            ['code' => 'bilingual', 'name' => 'Bilingüe'],
            ['code' => 'travel', 'name' => 'Con viajes'],
        ]);

        // El catálogo de puestos (`positions`) vive en PositionSeeder: necesita
        // que las áreas funcionales existan para resolver functional_area_id.
    }

    /**
     * @param  class-string  $model
     * @param  array<int, array{code: string, name: string, description?: string}>  $items
     */
    private function seedList(string $model, array $items): void
    {
        foreach ($items as $i => $data) {
            $model::updateOrCreate(
                ['code' => $data['code']],
                $data + ['sort_order' => $i + 1, 'is_active' => true]
            );
        }
    }
}
