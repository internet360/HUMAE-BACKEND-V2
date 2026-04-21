<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_favorites', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('recruiter_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('candidate_profile_id')->constrained('candidate_profiles')->cascadeOnDelete();
            $t->timestamps();

            $t->unique(['recruiter_id', 'candidate_profile_id'], 'uq_directory_favorite');
            $t->index('candidate_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_favorites');
    }
};
