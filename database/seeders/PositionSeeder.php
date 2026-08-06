<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FunctionalArea;
use App\Models\Position;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Catálogo maestro de puestos (`positions`).
 *
 * Fuente de verdad: documento del cliente `PUESTOS DE BUSQUEDA.docx`
 * ("Listado maestro estructurado por Áreas Macro y sus Puestos Estandarizados
 * más demandados") — 7 áreas macro / 60 puestos.
 *
 * Reglas aplicadas al importar el documento:
 *
 * 1. Los nombres son los canónicos del cliente, tal cual el documento. Única
 *    excepción: "Gerente Administración y Fianzas" → "…y Finanzas" (errata
 *    evidente del documento; "Fianzas" es otra cosa).
 * 2. Cuando un puesto del documento ya existía en el catálogo se REUSA su
 *    `code` en lugar de crear un duplicado, porque `candidate_profiles.
 *    position_id` y `vacancies.position_id` ya pueden apuntar a él. Solo se
 *    actualiza el nombre al del cliente. Son 7: software_engineer,
 *    data_analyst, accountant, customer_service, sales_representative,
 *    marketing_specialist, recruiter.
 * 3. Las áreas macro del documento son más gruesas que el catálogo
 *    `functional_areas` (que ya distingue Calidad, Mantenimiento, Almacén,
 *    Compras…). Cada puesto se asocia al área MÁS PRECISA que exista, y el
 *    área macro del documento queda como encabezado de sección. Así el
 *    filtro Área → Puesto es útil y no se pierde la estructura del doc.
 */
class PositionSeeder extends Seeder
{
    /**
     * Puestos del documento del cliente, en orden de documento.
     *
     * @var array<int, array{code: string, name: string, area: string}>
     */
    private const CLIENT_POSITIONS = [
        // ── 1. Tecnología y Sistemas (IT) ────────────────────────────────
        ['code' => 'software_developer', 'name' => 'Desarrollador/Diseño de Software', 'area' => 'it_systems'],
        ['code' => 'software_engineer', 'name' => 'Ingeniero de Software / Sistemas', 'area' => 'it_systems'],
        ['code' => 'data_analyst', 'name' => 'Analista de Datos', 'area' => 'data'],
        ['code' => 'network_administrator', 'name' => 'Administrador de Redes y Soporte TI', 'area' => 'it_systems'],
        ['code' => 'cybersecurity_specialist', 'name' => 'Especialista en Ciberseguridad', 'area' => 'it_systems'],
        ['code' => 'technical_support', 'name' => 'Soporte Técnico', 'area' => 'it_systems'],
        ['code' => 'it_project_manager', 'name' => 'Gerente de Proyectos TI', 'area' => 'it_systems'],
        ['code' => 'it_intern', 'name' => 'Practicante Sistemas', 'area' => 'it_systems'],

        // ── 2. Ingeniería, Manufactura y Operaciones ─────────────────────
        ['code' => 'industrial_maintenance_technician', 'name' => 'Técnico en Mantenimiento Industrial', 'area' => 'maintenance'],
        ['code' => 'mechatronics_technician', 'name' => 'Técnico en Mecatrónica / Automatización', 'area' => 'maintenance'],
        ['code' => 'cnc_machine_operator', 'name' => 'Operador de Maquinaria CNC / Inyección', 'area' => 'manufacturing'],
        ['code' => 'production_supervisor', 'name' => 'Supervisor de Producción / Planta', 'area' => 'manufacturing'],
        ['code' => 'quality_engineer', 'name' => 'Ingeniero de Calidad', 'area' => 'quality'],
        ['code' => 'process_engineer', 'name' => 'Ingeniero de Procesos / Manufactura', 'area' => 'engineering'],
        ['code' => 'plant_manager', 'name' => 'Gerente Planta', 'area' => 'manufacturing'],
        ['code' => 'safety_hygiene_specialist', 'name' => 'Especialista en Seguridad e Higiene', 'area' => 'industrial_safety'],
        ['code' => 'engineering_intern', 'name' => 'Practicante Ingeniería', 'area' => 'engineering'],

        // ── 3. Logística, Cadena de Suministro y Compras ─────────────────
        ['code' => 'warehouse_assistant', 'name' => 'Auxiliar de Almacén / Inventarios', 'area' => 'warehouse'],
        ['code' => 'warehouse_supervisor', 'name' => 'Supervisor de Almacén / Bodega', 'area' => 'warehouse'],
        ['code' => 'logistics_analyst', 'name' => 'Analista de Logística y Distribución', 'area' => 'logistics'],
        ['code' => 'buyer', 'name' => 'Comprador', 'area' => 'purchasing'],
        ['code' => 'logistics_coordinator', 'name' => 'Coordinador de Logística / Distribución', 'area' => 'logistics'],
        ['code' => 'operations_manager', 'name' => 'Gerente de Operaciones', 'area' => 'operations'],
        ['code' => 'logistics_intern', 'name' => 'Practicante Logística', 'area' => 'logistics'],

        // ── 4. Administración, Finanzas y Legal ──────────────────────────
        ['code' => 'executive_assistant', 'name' => 'Asistente Ejecutivo', 'area' => 'admin'],
        ['code' => 'accounting_assistant', 'name' => 'Auxiliar Contable / Facturista', 'area' => 'finance'],
        ['code' => 'accountant', 'name' => 'Contador Público / Auditor', 'area' => 'finance'],
        ['code' => 'accounts_receivable_payable', 'name' => 'Cuentas por Cobrar y Pagar', 'area' => 'finance'],
        ['code' => 'general_accountant', 'name' => 'Contador General', 'area' => 'finance'],
        ['code' => 'admin_finance_manager', 'name' => 'Gerente Administración y Finanzas', 'area' => 'admin'],
        ['code' => 'financial_analyst', 'name' => 'Analista Financiero / de Presupuestos', 'area' => 'finance'],
        ['code' => 'business_administrator', 'name' => 'Administrador de Empresas / Generalista', 'area' => 'admin'],
        ['code' => 'auditor', 'name' => 'Auditor', 'area' => 'finance'],
        ['code' => 'legal_counsel', 'name' => 'Asesor Legal / Abogado Corporativo', 'area' => 'legal'],
        ['code' => 'accounting_intern', 'name' => 'Practicantes Contables', 'area' => 'finance'],

        // ── 5. Comercial, Ventas y Marketing Digital ─────────────────────
        ['code' => 'sales_executive', 'name' => 'Ejecutivo de Ventas', 'area' => 'sales'],
        ['code' => 'customer_service', 'name' => 'Asesor Comercial / Atención al Cliente', 'area' => 'customer'],
        ['code' => 'sales_representative', 'name' => 'Representante de Ventas', 'area' => 'sales'],
        ['code' => 'account_manager', 'name' => 'Gerente de Cuenta', 'area' => 'sales'],
        ['code' => 'marketing_specialist', 'name' => 'Especialista en Marketing Digital', 'area' => 'marketing'],
        ['code' => 'creative_director', 'name' => 'Director Creativo', 'area' => 'design'],
        ['code' => 'community_manager', 'name' => 'Community Manager', 'area' => 'marketing'],
        ['code' => 'graphic_designer', 'name' => 'Diseñador Gráfico', 'area' => 'design'],
        ['code' => 'public_relations', 'name' => 'Relaciones Públicas', 'area' => 'marketing'],
        ['code' => 'marketing_intern', 'name' => 'Practicantes MKT', 'area' => 'marketing'],

        // ── 6. Recursos Humanos y Talento ────────────────────────────────
        ['code' => 'recruiter', 'name' => 'Reclutador', 'area' => 'hr'],
        ['code' => 'hr_generalist', 'name' => 'Generalista de Recursos Humanos', 'area' => 'hr'],
        ['code' => 'hr_coordinator', 'name' => 'Coordinador / Jefe Recursos Humanos', 'area' => 'hr'],
        ['code' => 'payroll_analyst', 'name' => 'Analista de Nómina y Compensaciones', 'area' => 'hr'],
        ['code' => 'training_development_specialist', 'name' => 'Especialista en Capacitación y Desarrollo Organizacional', 'area' => 'hr'],
        ['code' => 'hr_manager', 'name' => 'Gerente Recursos Humanos', 'area' => 'hr'],
        ['code' => 'hr_director', 'name' => 'Director Recursos Humanos', 'area' => 'hr'],
        ['code' => 'hr_intern', 'name' => 'Practicante Recursos Humanos', 'area' => 'hr'],

        // ── 7. Salud, Laboratorio y Biotecnología ────────────────────────
        ['code' => 'nursing_technician', 'name' => 'Técnico en Enfermería', 'area' => 'health'],
        ['code' => 'registered_nurse', 'name' => 'Licenciado en Enfermería', 'area' => 'health'],
        ['code' => 'lab_technician', 'name' => 'Técnico de Laboratorio Clínico / Químico', 'area' => 'health'],
        ['code' => 'physician', 'name' => 'Médico General / Especialista', 'area' => 'health'],
        ['code' => 'medical_sales_representative', 'name' => 'Representante Médico / Ventas', 'area' => 'health'],
        ['code' => 'healthcare_administrator', 'name' => 'Administrador de Centros de Salud / Hospitales', 'area' => 'health'],
        ['code' => 'health_intern', 'name' => 'Practicantes Salud', 'area' => 'health'],
    ];

    /**
     * Puestos que ya existían en el catálogo y NO están en el documento del
     * cliente. Se conservan porque perfiles y vacantes guardados pueden
     * apuntar a ellos; solo se les asigna área para que no queden sin
     * clasificar en el selector Área → Puesto. Van al final del orden.
     *
     * @var array<int, array{code: string, name: string, area: string}>
     */
    private const LEGACY_POSITIONS = [
        ['code' => 'frontend_developer', 'name' => 'Desarrollador/a Frontend', 'area' => 'it_systems'],
        ['code' => 'backend_developer', 'name' => 'Desarrollador/a Backend', 'area' => 'it_systems'],
        ['code' => 'fullstack_developer', 'name' => 'Desarrollador/a Full Stack', 'area' => 'it_systems'],
        ['code' => 'mobile_developer', 'name' => 'Desarrollador/a Mobile', 'area' => 'it_systems'],
        ['code' => 'devops_engineer', 'name' => 'DevOps / SRE', 'area' => 'it_systems'],
        ['code' => 'qa_engineer', 'name' => 'QA Engineer', 'area' => 'it_systems'],
        ['code' => 'data_scientist', 'name' => 'Científico/a de Datos', 'area' => 'data'],
        ['code' => 'product_manager', 'name' => 'Product Manager', 'area' => 'product'],
        ['code' => 'ux_designer', 'name' => 'Diseñador/a UX/UI', 'area' => 'design'],
        ['code' => 'project_manager', 'name' => 'Project Manager', 'area' => 'operations'],
        ['code' => 'account_executive', 'name' => 'Ejecutivo/a de Cuentas', 'area' => 'sales'],
        ['code' => 'hr_specialist', 'name' => 'Especialista de RH', 'area' => 'hr'],
        ['code' => 'administrative_assistant', 'name' => 'Asistente Administrativo', 'area' => 'admin'],
    ];

    /** Offset de `sort_order` para que los puestos legacy queden al final. */
    private const LEGACY_SORT_OFFSET = 900;

    public function run(): void
    {
        /** @var array<string, int> $areaIds */
        $areaIds = FunctionalArea::query()->pluck('id', 'code')->all();

        foreach (self::CLIENT_POSITIONS as $i => $position) {
            $this->upsert($position, $i + 1, $areaIds);
        }

        foreach (self::LEGACY_POSITIONS as $i => $position) {
            $this->upsert($position, self::LEGACY_SORT_OFFSET + $i + 1, $areaIds);
        }
    }

    /**
     * @param  array{code: string, name: string, area: string}  $position
     * @param  array<string, int>  $areaIds
     */
    private function upsert(array $position, int $sortOrder, array $areaIds): void
    {
        $areaId = $areaIds[$position['area']] ?? null;

        if ($areaId === null) {
            throw new RuntimeException(sprintf(
                'El área funcional "%s" (puesto "%s") no existe en el catálogo. '
                .'JobTaxonomySeeder debe correr antes de PositionSeeder.',
                $position['area'],
                $position['code'],
            ));
        }

        Position::updateOrCreate(
            ['code' => $position['code']],
            [
                'name' => $position['name'],
                'functional_area_id' => $areaId,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ],
        );
    }
}
