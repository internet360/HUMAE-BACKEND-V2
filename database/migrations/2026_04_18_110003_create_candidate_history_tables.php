<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_experiences', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('candidate_profile_id')->constrained('candidate_profiles')->cascadeOnDelete();
            $t->string('company_name', 200);
            $t->string('position_title', 200);
            $t->foreignId('functional_area_id')->nullable()->constrained('functional_areas')->nullOnDelete();
            $t->string('location', 200)->nullable();
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->boolean('is_current')->default(false);
            $t->longText('description')->nullable();
            $t->longText('achievements')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();

            $t->index(['candidate_profile_id', 'sort_order']);
            $t->index('start_date');
        });

        Schema::create('candidate_educations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('candidate_profile_id')->constrained('candidate_profiles')->cascadeOnDelete();
            $t->foreignId('degree_level_id')->nullable()->constrained('degree_levels')->nullOnDelete();
            $t->string('institution', 200);
            $t->string('field_of_study', 200)->nullable();
            $t->string('location', 200)->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->boolean('is_current')->default(false);
            $t->string('status', 30)->nullable();   // 'en_curso' | 'concluido' | 'trunco' | 'titulado'
            $t->string('credential_number', 80)->nullable(); // cédula profesional
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();

            $t->index(['candidate_profile_id', 'sort_order']);
        });

        Schema::create('candidate_courses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('candidate_profile_id')->constrained('candidate_profiles')->cascadeOnDelete();
            $t->string('name', 200);
            $t->string('institution', 200)->nullable();
            $t->unsignedSmallInteger('duration_hours')->nullable();
            $t->date('completed_at')->nullable();
            $t->string('certificate_url', 500)->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();

            $t->index(['candidate_profile_id', 'sort_order']);
        });

        Schema::create('candidate_certifications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('candidate_profile_id')->constrained('candidate_profiles')->cascadeOnDelete();
            $t->string('name', 200);
            $t->string('issuer', 200);
            $t->string('credential_id', 120)->nullable();
            $t->string('credential_url', 500)->nullable();
            $t->date('issued_at')->nullable();
            $t->date('expires_at')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();

            $t->index(['candidate_profile_id', 'sort_order']);
        });

        Schema::create('candidate_references', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('candidate_profile_id')->constrained('candidate_profiles')->cascadeOnDelete();
            $t->string('name', 200);
            $t->string('relationship', 120)->nullable();
            $t->string('company', 200)->nullable();
            $t->string('position_title', 200)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email', 160)->nullable();
            $t->longText('notes')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();

            $t->index(['candidate_profile_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_references');
        Schema::dropIfExists('candidate_certifications');
        Schema::dropIfExists('candidate_courses');
        Schema::dropIfExists('candidate_educations');
        Schema::dropIfExists('candidate_experiences');
    }
};
