<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assigned_recruiter_id')->nullable()->constrained('users')->nullOnDelete();

            // Identidad
            $t->string('code', 40)->unique();                        // HUM-2026-0001
            $t->string('title', 200);
            $t->string('slug', 220);
            $t->longText('description');
            $t->longText('responsibilities')->nullable();
            $t->longText('requirements')->nullable();
            $t->longText('benefits')->nullable();

            // Clasificación
            $t->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $t->foreignId('functional_area_id')->nullable()->constrained('functional_areas')->nullOnDelete();
            $t->foreignId('vacancy_category_id')->nullable()->constrained('vacancy_categories')->nullOnDelete();
            $t->foreignId('vacancy_type_id')->nullable()->constrained('vacancy_types')->nullOnDelete();
            $t->foreignId('vacancy_shift_id')->nullable()->constrained('vacancy_shifts')->nullOnDelete();
            $t->foreignId('career_level_id')->nullable()->constrained('career_levels')->nullOnDelete();
            $t->foreignId('degree_level_id')->nullable()->constrained('degree_levels')->nullOnDelete();

            // Requisitos cuantitativos
            $t->unsignedSmallInteger('min_years_of_experience')->nullable();
            $t->unsignedSmallInteger('max_years_of_experience')->nullable();
            $t->unsignedSmallInteger('min_age')->nullable();
            $t->unsignedSmallInteger('max_age')->nullable();
            $t->string('gender_preference', 20)->nullable();          // 'any' | 'male' | 'female'
            $t->unsignedSmallInteger('vacancies_count')->default(1);

            // Ubicación + modalidad
            $t->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $t->foreignId('state_id')->nullable()->constrained('states')->nullOnDelete();
            $t->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $t->boolean('is_remote')->default(false);
            $t->boolean('is_hybrid')->default(false);
            $t->string('work_location', 300)->nullable();

            // Salario
            $t->foreignId('salary_currency_id')->nullable()->constrained('salary_currencies')->nullOnDelete();
            $t->decimal('salary_min', 12, 2)->nullable();
            $t->decimal('salary_max', 12, 2)->nullable();
            $t->string('salary_period', 20)->nullable();              // see SalaryPeriod enum
            $t->boolean('salary_is_public')->default(false);

            // Estado de la vacante (privada por reglas de negocio)
            $t->string('state', 30)->default('borrador');             // see VacancyState enum
            $t->string('priority', 20)->default('normal');            // see Priority enum
            $t->timestamp('published_at')->nullable();
            $t->date('closes_at')->nullable();
            $t->timestamp('filled_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 500)->nullable();

            // SLA / acuerdo comercial
            $t->decimal('fee_amount', 12, 2)->nullable();             // honorarios HUMAE
            $t->decimal('fee_percentage', 5, 2)->nullable();          // % sobre salario anual
            $t->unsignedSmallInteger('sla_days')->nullable();         // días comprometidos hasta shortlist

            $t->longText('internal_notes')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'state']);
            $t->index(['state', 'priority']);
            $t->index('assigned_recruiter_id');
            $t->index('published_at');
            $t->index(['country_id', 'state_id', 'city_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancies');
    }
};
