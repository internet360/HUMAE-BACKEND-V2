<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_requests', function (Blueprint $t): void {
            // Internal staff notes: never exposed to the requester.
            $t->text('admin_notes')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_requests', function (Blueprint $t): void {
            $t->dropColumn('admin_notes');
        });
    }
};
