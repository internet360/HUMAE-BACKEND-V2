<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Language;
use App\Models\Skill;
use Illuminate\Database\Seeder;

class TalentTaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $skills = [
            // Técnicas — desarrollo
            ['code' => 'javascript', 'name' => 'JavaScript', 'category' => 'tecnica'],
            ['code' => 'typescript', 'name' => 'TypeScript', 'category' => 'tecnica'],
            ['code' => 'python', 'name' => 'Python', 'category' => 'tecnica'],
            ['code' => 'php', 'name' => 'PHP', 'category' => 'tecnica'],
            ['code' => 'java', 'name' => 'Java', 'category' => 'tecnica'],
            ['code' => 'csharp', 'name' => 'C#', 'category' => 'tecnica'],
            ['code' => 'sql', 'name' => 'SQL', 'category' => 'tecnica'],
            ['code' => 'react', 'name' => 'React', 'category' => 'tecnica'],
            ['code' => 'nextjs', 'name' => 'Next.js', 'category' => 'tecnica'],
            ['code' => 'vue', 'name' => 'Vue.js', 'category' => 'tecnica'],
            ['code' => 'angular', 'name' => 'Angular', 'category' => 'tecnica'],
            ['code' => 'nodejs', 'name' => 'Node.js', 'category' => 'tecnica'],
            ['code' => 'laravel', 'name' => 'Laravel', 'category' => 'tecnica'],
            ['code' => 'django', 'name' => 'Django', 'category' => 'tecnica'],
            ['code' => 'docker', 'name' => 'Docker', 'category' => 'tecnica'],
            ['code' => 'kubernetes', 'name' => 'Kubernetes', 'category' => 'tecnica'],
            ['code' => 'aws', 'name' => 'AWS', 'category' => 'tecnica'],
            ['code' => 'gcp', 'name' => 'Google Cloud', 'category' => 'tecnica'],
            ['code' => 'azure', 'name' => 'Microsoft Azure', 'category' => 'tecnica'],
            ['code' => 'git', 'name' => 'Git', 'category' => 'tecnica'],

            // Herramientas de negocio
            ['code' => 'excel', 'name' => 'Microsoft Excel', 'category' => 'herramienta'],
            ['code' => 'power_bi', 'name' => 'Power BI', 'category' => 'herramienta'],
            ['code' => 'tableau', 'name' => 'Tableau', 'category' => 'herramienta'],
            ['code' => 'salesforce', 'name' => 'Salesforce', 'category' => 'herramienta'],
            ['code' => 'hubspot', 'name' => 'HubSpot', 'category' => 'herramienta'],
            ['code' => 'sap', 'name' => 'SAP', 'category' => 'herramienta'],
            ['code' => 'figma', 'name' => 'Figma', 'category' => 'herramienta'],

            // Blandas
            ['code' => 'leadership', 'name' => 'Liderazgo', 'category' => 'blanda'],
            ['code' => 'teamwork', 'name' => 'Trabajo en equipo', 'category' => 'blanda'],
            ['code' => 'communication', 'name' => 'Comunicación', 'category' => 'blanda'],
            ['code' => 'problem_solving', 'name' => 'Resolución de problemas', 'category' => 'blanda'],
            ['code' => 'time_management', 'name' => 'Gestión del tiempo', 'category' => 'blanda'],
            ['code' => 'adaptability', 'name' => 'Adaptabilidad', 'category' => 'blanda'],
            ['code' => 'negotiation', 'name' => 'Negociación', 'category' => 'blanda'],
            ['code' => 'critical_thinking', 'name' => 'Pensamiento crítico', 'category' => 'blanda'],
            ['code' => 'creativity', 'name' => 'Creatividad', 'category' => 'blanda'],
            ['code' => 'empathy', 'name' => 'Empatía', 'category' => 'blanda'],

            // Gestión / Metodologías
            ['code' => 'agile', 'name' => 'Metodologías Ágiles', 'category' => 'metodologia'],
            ['code' => 'scrum', 'name' => 'Scrum', 'category' => 'metodologia'],
            ['code' => 'kanban', 'name' => 'Kanban', 'category' => 'metodologia'],
            ['code' => 'pmp', 'name' => 'Gestión de proyectos (PMP)', 'category' => 'metodologia'],
        ];

        foreach ($skills as $i => $data) {
            Skill::updateOrCreate(
                ['code' => $data['code']],
                $data + ['sort_order' => $i + 1, 'is_active' => true]
            );
        }

        $languages = [
            ['code' => 'es', 'name' => 'Español', 'native_name' => 'Español'],
            ['code' => 'en', 'name' => 'Inglés', 'native_name' => 'English'],
            ['code' => 'fr', 'name' => 'Francés', 'native_name' => 'Français'],
            ['code' => 'pt', 'name' => 'Portugués', 'native_name' => 'Português'],
            ['code' => 'de', 'name' => 'Alemán', 'native_name' => 'Deutsch'],
            ['code' => 'it', 'name' => 'Italiano', 'native_name' => 'Italiano'],
            ['code' => 'zh', 'name' => 'Chino mandarín', 'native_name' => '中文'],
            ['code' => 'ja', 'name' => 'Japonés', 'native_name' => '日本語'],
            ['code' => 'ko', 'name' => 'Coreano', 'native_name' => '한국어'],
        ];

        foreach ($languages as $i => $data) {
            Language::updateOrCreate(
                ['code' => $data['code']],
                $data + ['sort_order' => $i + 1, 'is_active' => true]
            );
        }
    }
}
