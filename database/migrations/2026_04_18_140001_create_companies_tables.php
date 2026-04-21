<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t): void {
            $t->id();
            $t->string('legal_name', 200);
            $t->string('trade_name', 200)->nullable();
            $t->string('slug', 200)->unique();
            $t->string('rfc', 13)->nullable();                       // RFC MX persona moral
            $t->longText('description')->nullable();
            $t->string('website', 300)->nullable();
            $t->string('logo_url', 600)->nullable();
            $t->string('cover_url', 600)->nullable();

            // Taxonomías
            $t->foreignId('industry_id')->nullable()->constrained('industries')->nullOnDelete();
            $t->foreignId('company_size_id')->nullable()->constrained('company_sizes')->nullOnDelete();
            $t->foreignId('ownership_type_id')->nullable()->constrained('ownership_types')->nullOnDelete();
            $t->unsignedSmallInteger('founded_year')->nullable();

            // Contacto principal
            $t->string('contact_name', 200)->nullable();
            $t->string('contact_email', 160)->nullable();
            $t->string('contact_phone', 30)->nullable();
            $t->string('contact_position', 200)->nullable();

            // Dirección
            $t->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $t->foreignId('state_id')->nullable()->constrained('states')->nullOnDelete();
            $t->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $t->string('address_line', 300)->nullable();
            $t->string('postal_code', 15)->nullable();

            // Redes sociales
            $t->string('linkedin_url', 300)->nullable();
            $t->string('facebook_url', 300)->nullable();
            $t->string('instagram_url', 300)->nullable();
            $t->string('twitter_url', 300)->nullable();

            // Estado comercial
            $t->string('status', 30)->default('active');             // active | paused | archived
            $t->boolean('is_verified')->default(false);
            $t->timestamp('verified_at')->nullable();
            $t->foreignId('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $t->longText('internal_notes')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['industry_id', 'company_size_id']);
            $t->index('status');
            $t->index('account_manager_id');
        });

        Schema::create('company_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('role', 40)->default('member');              // see CompanyMemberRole enum
            $t->string('job_title', 200)->nullable();
            $t->boolean('is_primary_contact')->default(false);
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_members');
        Schema::dropIfExists('companies');
    }
};
