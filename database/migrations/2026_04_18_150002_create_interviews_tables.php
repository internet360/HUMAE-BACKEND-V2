<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interviews', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('vacancy_assignment_id')->constrained('vacancy_assignments')->cascadeOnDelete();
            $t->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();

            $t->unsignedSmallInteger('round')->default(1);          // 1ª, 2ª, panel...
            $t->string('title', 200)->nullable();
            $t->string('state', 30)->default('propuesta');          // see InterviewState enum
            $t->string('mode', 20)->default('online');              // see InterviewMode enum

            $t->dateTime('scheduled_at');
            $t->unsignedSmallInteger('duration_minutes')->default(60);
            $t->string('timezone', 60)->default('America/Mexico_City');

            // Virtual
            $t->string('meeting_url', 600)->nullable();
            $t->string('meeting_provider', 40)->nullable();         // 'google_meet', 'zoom', 'teams'
            $t->string('meeting_id', 120)->nullable();

            // Presencial
            $t->string('location', 400)->nullable();

            // Resultado
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->unsignedTinyInteger('rating')->nullable();          // 0-10
            $t->longText('recruiter_feedback')->nullable();
            $t->longText('company_feedback')->nullable();
            $t->string('recommendation', 30)->nullable();           // 'advance' | 'hold' | 'reject'

            $t->timestamps();
            $t->softDeletes();

            $t->index(['vacancy_assignment_id', 'state']);
            $t->index('scheduled_at');
            $t->index('state');
        });

        Schema::create('interview_reschedules', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('interview_id')->constrained('interviews')->cascadeOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->dateTime('previous_scheduled_at');
            $t->dateTime('new_scheduled_at');
            $t->string('reason', 500)->nullable();
            $t->timestamps();

            $t->index('interview_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_reschedules');
        Schema::dropIfExists('interviews');
    }
};
