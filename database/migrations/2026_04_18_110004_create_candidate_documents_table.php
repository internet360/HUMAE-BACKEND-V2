<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('candidate_profile_id')->constrained('candidate_profiles')->cascadeOnDelete();
            $t->string('type', 40);                              // see DocumentType enum
            $t->string('title', 200);
            $t->string('file_url', 600);
            $t->string('file_provider', 40)->default('cloudinary');
            $t->string('file_public_id', 300)->nullable();       // Cloudinary public_id
            $t->string('mime_type', 80)->nullable();
            $t->unsignedInteger('file_size_bytes')->nullable();
            $t->boolean('is_internal')->default(false);          // true = visible solo a HUMAE
            $t->timestamp('uploaded_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['candidate_profile_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_documents');
    }
};
