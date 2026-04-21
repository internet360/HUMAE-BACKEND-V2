<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_submissions', function (Blueprint $t): void {
            $t->id();
            $t->string('type', 30)->default('contact');           // 'contact' | 'company_request' | 'support'
            $t->string('name', 200);
            $t->string('email', 160);
            $t->string('phone', 30)->nullable();
            $t->string('company', 200)->nullable();
            $t->string('subject', 300)->nullable();
            $t->longText('message');
            $t->string('source', 80)->nullable();                 // 'landing' | 'contacto' | 'empresas'

            $t->string('status', 30)->default('new');             // 'new' | 'in_review' | 'contacted' | 'closed'
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->longText('internal_notes')->nullable();
            $t->timestamp('responded_at')->nullable();

            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->json('metadata')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['type', 'status']);
            $t->index('assigned_to');
            $t->index('email');
        });

        Schema::create('settings', function (Blueprint $t): void {
            $t->id();
            $t->string('key', 120)->unique();
            $t->string('group', 80)->default('general');
            $t->longText('value')->nullable();
            $t->string('type', 20)->default('string');            // 'string' | 'int' | 'bool' | 'json'
            $t->string('label', 200)->nullable();
            $t->string('description', 500)->nullable();
            $t->boolean('is_public')->default(false);
            $t->timestamps();

            $t->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('contact_submissions');
    }
};
