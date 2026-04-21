<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_skills', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('candidate_profile_id')->constrained('candidate_profiles')->cascadeOnDelete();
            $t->foreignId('skill_id')->constrained('skills')->restrictOnDelete();
            $t->string('level', 30);                     // see SkillLevel enum
            $t->unsignedSmallInteger('years_of_experience')->nullable();
            $t->timestamps();

            $t->unique(['candidate_profile_id', 'skill_id']);
        });

        Schema::create('candidate_languages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('candidate_profile_id')->constrained('candidate_profiles')->cascadeOnDelete();
            $t->foreignId('language_id')->constrained('languages')->restrictOnDelete();
            $t->string('level', 30);                     // see LanguageLevel enum
            $t->timestamps();

            $t->unique(['candidate_profile_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_languages');
        Schema::dropIfExists('candidate_skills');
    }
};
