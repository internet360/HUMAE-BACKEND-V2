<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CareerLevel;
use App\Models\DegreeLevel;
use App\Models\FunctionalArea;
use App\Models\Position;
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

        $this->seedList(FunctionalArea::class, [
            ['code' => 'engineering', 'name' => 'Ingeniería / Desarrollo'],
            ['code' => 'product', 'name' => 'Producto'],
            ['code' => 'design', 'name' => 'Diseño'],
            ['code' => 'data', 'name' => 'Datos / Analítica'],
            ['code' => 'marketing', 'name' => 'Marketing'],
            ['code' => 'sales', 'name' => 'Ventas'],
            ['code' => 'customer', 'name' => 'Atención al cliente / Soporte'],
            ['code' => 'operations', 'name' => 'Operaciones'],
            ['code' => 'finance', 'name' => 'Finanzas / Contabilidad'],
            ['code' => 'hr', 'name' => 'Recursos Humanos'],
            ['code' => 'legal', 'name' => 'Legal / Compliance'],
            ['code' => 'admin', 'name' => 'Administración'],
            ['code' => 'logistics', 'name' => 'Logística / Cadena de suministro'],
            ['code' => 'manufacturing', 'name' => 'Producción / Manufactura'],
            ['code' => 'other', 'name' => 'Otro'],
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

        $this->seedList(Position::class, [
            ['code' => 'software_engineer', 'name' => 'Ingeniero/a de Software'],
            ['code' => 'frontend_developer', 'name' => 'Desarrollador/a Frontend'],
            ['code' => 'backend_developer', 'name' => 'Desarrollador/a Backend'],
            ['code' => 'fullstack_developer', 'name' => 'Desarrollador/a Full Stack'],
            ['code' => 'mobile_developer', 'name' => 'Desarrollador/a Mobile'],
            ['code' => 'devops_engineer', 'name' => 'DevOps / SRE'],
            ['code' => 'data_analyst', 'name' => 'Analista de Datos'],
            ['code' => 'data_scientist', 'name' => 'Científico/a de Datos'],
            ['code' => 'product_manager', 'name' => 'Product Manager'],
            ['code' => 'ux_designer', 'name' => 'Diseñador/a UX/UI'],
            ['code' => 'qa_engineer', 'name' => 'QA Engineer'],
            ['code' => 'project_manager', 'name' => 'Project Manager'],
            ['code' => 'account_executive', 'name' => 'Ejecutivo/a de Cuentas'],
            ['code' => 'sales_representative', 'name' => 'Representante de Ventas'],
            ['code' => 'marketing_specialist', 'name' => 'Especialista de Marketing'],
            ['code' => 'hr_specialist', 'name' => 'Especialista de RH'],
            ['code' => 'recruiter', 'name' => 'Reclutador/a'],
            ['code' => 'accountant', 'name' => 'Contador/a'],
            ['code' => 'customer_service', 'name' => 'Atención al Cliente'],
            ['code' => 'administrative_assistant', 'name' => 'Asistente Administrativo'],
        ]);
    }

    /**
     * @param  class-string  $model
     * @param  array<int, array{code: string, name: string}>  $items
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
