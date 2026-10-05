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
            // Paths on the private `local` disk. CFDI files are legal records:
            // nothing in the app deletes them (not on cancellation, not on user deletion).
            $t->string('pdf_path')->nullable()->after('admin_notes');
            $t->string('xml_path')->nullable()->after('pdf_path');
            // Fiscal folio from the SAT stamp. Unique: one UUID backs one request.
            $t->uuid('cfdi_uuid')->nullable()->unique()->after('xml_path');
            $t->timestamp('issued_at')->nullable()->after('cfdi_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_requests', function (Blueprint $t): void {
            $t->dropUnique(['cfdi_uuid']);
            $t->dropColumn(['pdf_path', 'xml_path', 'cfdi_uuid', 'issued_at']);
        });
    }
};
