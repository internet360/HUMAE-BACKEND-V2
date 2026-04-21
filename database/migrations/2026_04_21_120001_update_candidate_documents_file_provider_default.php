<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_documents', function (Blueprint $t): void {
            $t->string('file_provider', 40)->default('local')->change();
        });

        DB::table('candidate_documents')
            ->where('file_provider', 'cloudinary')
            ->update(['file_provider' => 'local']);
    }

    public function down(): void
    {
        Schema::table('candidate_documents', function (Blueprint $t): void {
            $t->string('file_provider', 40)->default('cloudinary')->change();
        });
    }
};
