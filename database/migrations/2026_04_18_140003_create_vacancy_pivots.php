<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_skills', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $t->foreignId('skill_id')->constrained('skills')->restrictOnDelete();
            $t->string('required_level', 30)->nullable();        // see SkillLevel enum
            $t->boolean('is_required')->default(true);
            $t->timestamps();

            $t->unique(['vacancy_id', 'skill_id']);
        });

        Schema::create('vacancy_languages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $t->foreignId('language_id')->constrained('languages')->restrictOnDelete();
            $t->string('required_level', 30);                    // see LanguageLevel enum
            $t->boolean('is_required')->default(true);
            $t->timestamps();

            $t->unique(['vacancy_id', 'language_id']);
        });

        Schema::create('vacancy_tag_vacancy', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $t->foreignId('vacancy_tag_id')->constrained('vacancy_tags')->cascadeOnDelete();
            $t->timestamps();

            $t->unique(['vacancy_id', 'vacancy_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_tag_vacancy');
        Schema::dropIfExists('vacancy_languages');
        Schema::dropIfExists('vacancy_skills');
    }
};
