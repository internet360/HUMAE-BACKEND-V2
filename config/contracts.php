<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Contrato de prestación de servicios (empresa cliente)
    |--------------------------------------------------------------------------
    |
    | Términos comerciales vigentes que se estampan en el contrato al momento de
    | emitirlo. Hoy son iguales para todas las empresas.
    |
    | IMPORTANTE: estos valores son el término *vigente*, no la fuente de verdad
    | permanente. CompanyContractService copia este bloque completo a la columna
    | `company_contracts.terms` al firmar, y tanto el PDF como cualquier
    | reimpresión leen de esa copia. Si mañana cambia el porcentaje, los
    | contratos ya firmados conservan el que aceptaron.
    |
    */

    'version' => env('CONTRACT_TERMS_VERSION', '2026.1'),

    'provider_name' => env('CONTRACT_PROVIDER_NAME', 'Humae Consultoría de RH'),

    /*
    | Honorarios por candidato contratado (cláusula Tercera).
    |
    |   percentage_annual_gross → fee_value = % del sueldo bruto anualizado
    |   monthly_salary_multiple → fee_value = número de meses de sueldo bruto
    |   fixed_amount            → fee_value = monto MXN; requiere fee_amount_words
    */
    'fee_kind' => env('CONTRACT_FEE_KIND', 'percentage_annual_gross'),
    'fee_value' => env('CONTRACT_FEE_VALUE', 12),
    'fee_amount_words' => env('CONTRACT_FEE_AMOUNT_WORDS'),

    // Plazo de pago (cláusula Cuarta).
    'payment_days' => env('CONTRACT_PAYMENT_DAYS', 5),
    'payment_day_kind' => env('CONTRACT_PAYMENT_DAY_KIND', 'habiles'), // habiles | naturales

    // Garantía de sustitución en días naturales (cláusula Quinta).
    'warranty_days' => env('CONTRACT_WARRANTY_DAYS', 90),

    // Lugar de firma y fuero (cláusula Séptima y cierre).
    'city' => env('CONTRACT_CITY', 'Querétaro, Querétaro'),
    'jurisdiction' => env(
        'CONTRACT_JURISDICTION',
        'la ciudad de Querétaro, Querétaro, Estados Unidos Mexicanos',
    ),

    /*
    | Apoderado de HUMAE que firma por EL PRESTADOR. `signature_path` es una ruta
    | relativa a resource_path() con la firma en PNG. Si falta el nombre o la
    | firma, el contrato saldría firmado por una sola parte: el servicio lo
    | valida antes de generar el PDF.
    */
    'signatory' => [
        'name' => env('CONTRACT_SIGNATORY_NAME'),
        'title' => env('CONTRACT_SIGNATORY_TITLE'),
        'signature_path' => env('CONTRACT_SIGNATORY_SIGNATURE', 'views/pdf/humae-signature.png'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Archivos que se piden al firmar
    |--------------------------------------------------------------------------
    */

    'uploads' => [
        'max_kilobytes' => 8192,
        'image_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

];
