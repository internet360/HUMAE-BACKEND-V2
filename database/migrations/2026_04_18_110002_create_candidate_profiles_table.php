<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Identity
            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->string('headline', 200)->nullable();      // "UX Designer con 5 años..."
            $table->longText('summary')->nullable();          // bio / about me
            $table->date('birth_date')->nullable();
            $table->enum('gender', ['male', 'female', 'other', 'prefer_not_to_say'])->nullable();
            $table->string('curp', 18)->nullable();           // CURP MX
            $table->string('rfc', 13)->nullable();            // RFC MX

            // Contact / social
            $table->string('contact_email', 160)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('linkedin_url', 300)->nullable();
            $table->string('portfolio_url', 300)->nullable();
            $table->string('github_url', 300)->nullable();

            // Location
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('address_line', 300)->nullable();
            $table->string('postal_code', 15)->nullable();

            // Professional snapshot
            $table->foreignId('career_level_id')->nullable()->constrained('career_levels')->nullOnDelete();
            $table->foreignId('functional_area_id')->nullable()->constrained('functional_areas')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->unsignedSmallInteger('years_of_experience')->nullable();

            // Salary expectation
            $table->foreignId('salary_currency_id')->nullable()->constrained('salary_currencies')->nullOnDelete();
            $table->decimal('expected_salary_min', 12, 2)->nullable();
            $table->decimal('expected_salary_max', 12, 2)->nullable();
            $table->string('expected_salary_period', 20)->nullable(); // see SalaryPeriod enum

            // Availability
            $table->string('availability', 30)->nullable();        // see AvailabilityType enum
            $table->date('available_from')->nullable();
            $table->boolean('open_to_relocation')->default(false);
            $table->boolean('open_to_remote')->default(false);

            // Domain state
            $table->string('state', 30)->default('registro_incompleto'); // see CandidateState enum
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('state');
            $table->index(['country_id', 'state_id', 'city_id']);
            $table->index('functional_area_id');
            $table->index('career_level_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_profiles');
    }
};
