<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('psychometric_attempts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('candidate_profile_id')->constrained('candidate_profiles')->cascadeOnDelete();
            $t->foreignId('psychometric_test_id')->constrained('psychometric_tests')->restrictOnDelete();
            $t->string('status', 30)->default('in_progress'); // see AttemptStatus enum
            $t->dateTime('started_at');
            $t->dateTime('submitted_at')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->timestamps();

            $t->index(['candidate_profile_id', 'psychometric_test_id'], 'idx_attempt_candidate_test');
            $t->index('status');
        });

        Schema::create('psychometric_answers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('psychometric_attempt_id')->constrained('psychometric_attempts')->cascadeOnDelete();
            $t->foreignId('psychometric_question_id')->constrained('psychometric_questions')->restrictOnDelete();
            $t->foreignId('psychometric_question_option_id')->nullable()->constrained('psychometric_question_options')->nullOnDelete();
            $t->string('value', 400)->nullable();             // para respuestas abiertas o numéricas
            $t->integer('score')->nullable();
            $t->unsignedInteger('time_spent_seconds')->nullable();
            $t->timestamps();

            $t->unique(['psychometric_attempt_id', 'psychometric_question_id'], 'uq_attempt_question');
        });

        Schema::create('psychometric_results', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('psychometric_attempt_id')->unique()->constrained('psychometric_attempts')->cascadeOnDelete();
            $t->decimal('total_score', 8, 2)->default(0);
            $t->decimal('percentile', 5, 2)->nullable();
            $t->string('grade', 20)->nullable();              // 'A', 'B', 'C', 'apto', 'no_apto'
            $t->boolean('passed')->default(false);
            $t->json('dimension_scores')->nullable();         // {"extraversion": 70, ...}
            $t->longText('summary')->nullable();              // interpretación textual
            $t->longText('recommendations')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psychometric_results');
        Schema::dropIfExists('psychometric_answers');
        Schema::dropIfExists('psychometric_attempts');
    }
};
