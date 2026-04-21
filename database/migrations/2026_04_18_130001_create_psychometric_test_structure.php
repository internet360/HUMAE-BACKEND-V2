<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('psychometric_tests', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 80)->unique();
            $t->string('name', 200);
            $t->longText('description')->nullable();
            $t->string('category', 80)->nullable();          // 'personalidad', 'aptitud', 'valores'
            $t->unsignedSmallInteger('time_limit_minutes')->nullable();
            $t->unsignedSmallInteger('passing_score')->nullable();
            $t->string('instructions', 2000)->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_required')->default(false);      // requerido para aprobar onboarding
            $t->timestamps();

            $t->index('category');
        });

        Schema::create('psychometric_test_sections', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('psychometric_test_id')->constrained('psychometric_tests')->cascadeOnDelete();
            $t->string('code', 80);
            $t->string('name', 200);
            $t->string('description', 1000)->nullable();
            $t->unsignedSmallInteger('time_limit_minutes')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();

            $t->unique(['psychometric_test_id', 'code']);
        });

        Schema::create('psychometric_questions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('psychometric_test_id')->constrained('psychometric_tests')->cascadeOnDelete();
            $t->foreignId('psychometric_test_section_id')->nullable()->constrained('psychometric_test_sections')->cascadeOnDelete();
            $t->string('type', 30);                          // see QuestionType enum
            $t->longText('prompt');
            $t->string('image_url', 600)->nullable();
            $t->string('dimension', 80)->nullable();         // e.g. 'extraversion', 'matematica'
            $t->unsignedSmallInteger('weight')->default(1);
            $t->boolean('is_reverse_scored')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();

            $t->index(['psychometric_test_id', 'dimension']);
        });

        Schema::create('psychometric_question_options', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('psychometric_question_id')->constrained('psychometric_questions')->cascadeOnDelete();
            $t->string('label', 400);
            $t->string('value', 80);
            $t->integer('score')->default(0);                // puntaje para respuestas ponderadas
            $t->boolean('is_correct')->default(false);       // para preguntas con respuesta única correcta
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();

            $t->index('psychometric_question_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psychometric_question_options');
        Schema::dropIfExists('psychometric_questions');
        Schema::dropIfExists('psychometric_test_sections');
        Schema::dropIfExists('psychometric_tests');
    }
};
