<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_assignments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $t->foreignId('candidate_profile_id')->constrained('candidate_profiles')->cascadeOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();    // reclutador que asigna

            $t->string('stage', 40)->default('presented');         // see AssignmentStage enum
            $t->string('priority', 20)->default('normal');         // see Priority enum
            $t->unsignedTinyInteger('score')->nullable();          // fit score 0-100
            $t->longText('recruiter_notes')->nullable();
            $t->longText('company_notes')->nullable();
            $t->longText('rejection_reason')->nullable();

            $t->timestamp('presented_at')->nullable();
            $t->timestamp('shortlisted_at')->nullable();
            $t->timestamp('interviewed_at')->nullable();
            $t->timestamp('offer_sent_at')->nullable();
            $t->timestamp('hired_at')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->timestamp('withdrawn_at')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->unique(['vacancy_id', 'candidate_profile_id'], 'uq_vacancy_candidate');
            $t->index(['vacancy_id', 'stage']);
            $t->index(['candidate_profile_id', 'stage']);
            $t->index('assigned_by');
        });

        Schema::create('vacancy_assignment_notes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('vacancy_assignment_id')->constrained('vacancy_assignments')->cascadeOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->string('visibility', 20)->default('internal');     // 'internal' | 'company' | 'candidate'
            $t->longText('body');
            $t->timestamps();

            $t->index(['vacancy_assignment_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_assignment_notes');
        Schema::dropIfExists('vacancy_assignments');
    }
};
