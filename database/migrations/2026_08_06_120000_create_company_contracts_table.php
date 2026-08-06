<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_contracts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('signed_by_user_id')->constrained('users')->restrictOnDelete();

            $t->string('folio', 40)->unique();                       // HUMAE-CTR-{año}-{secuencia}

            // Puesto que el firmante declara al firmar. No se toma de
            // `company_members.job_title`: lo que vale en el contrato es lo que
            // la persona afirmó ser en ese momento.
            $t->string('signer_title', 200);

            /*
             * Copia de los términos comerciales vigentes al firmar (honorarios,
             * garantía, plazo de pago, fuero). Se estampan aquí porque un
             * contrato firmado es inmutable: si config/contracts.php cambia, los
             * contratos ya emitidos conservan lo que la empresa aceptó.
             */
            $t->json('terms');

            // Archivos. Todos en el disco privado `local` — son datos personales
            // (INE, selfie) y un contrato. Nunca en `public`.
            $t->string('signature_path', 300);                       // firma trazada (PNG)
            $t->string('identity_path', 300);                        // identificación oficial
            $t->string('selfie_path', 300);
            $t->string('pdf_path', 300);                             // contrato generado

            /*
             * Huella del PDF y constancia NOM-151.
             *
             * `pdf_hash` es SHA-256 sobre el base64 del PDF almacenado (mismo
             * criterio que RED1A1, para que ambos sistemas reverifiquen igual).
             * Se calcula sobre el archivo guardado: DomPDF escribe CreationDate
             * en los metadatos, así que un PDF regenerado da otro hash.
             *
             * `timestamp_path` queda nullable a propósito: si CINCEL está caído
             * la firma ya ocurrió y no se pierde; el sello se puede reintentar.
             */
            $t->char('pdf_hash', 64);
            $t->string('timestamp_path', 300)->nullable();
            $t->timestamp('timestamped_at')->nullable();

            // Trazabilidad de la firma electrónica simple (arts. 89-99 CCom).
            $t->timestamp('signed_at');
            $t->timestamp('terms_accepted_at')->nullable();
            $t->timestamp('privacy_accepted_at')->nullable();
            $t->string('signed_ip', 45)->nullable();                 // cabe IPv6
            $t->string('signed_user_agent', 400)->nullable();

            $t->timestamps();

            // Un contrato vigente por empresa se resuelve por el más reciente;
            // el índice sirve tanto al lookup del gate como al historial.
            $t->index(['company_id', 'signed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_contracts');
    }
};
