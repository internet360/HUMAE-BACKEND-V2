<!DOCTYPE html>
{{--
    Contrato de prestación de servicios HUMAE (acceso a plataforma) — empresa cliente.

    Se renderiza con DomPDF desde CompanyContractService. El PDF resultante se sella
    con una constancia NOM-151 emitida por CINCEL, por lo que el documento debe cumplir
    dos requisitos:

    1. Ser autocontenido: ninguna referencia remota (imágenes, fuentes, hojas de estilo).
       Toda imagen llega como data URI. DomPDF corre con `isRemoteEnabled = false`.
    2. No contener el hash ni el sello: el hash SHA-256 se calcula sobre los bytes de
       este PDF ya generado, así que incluirlo aquí sería circular. La constancia .asn1
       se guarda como archivo aparte y se relaciona por el folio.

    Variables esperadas (todas obligatorias salvo las marcadas):
      $contract      CompanyContract  folio, signed_at, terms
      $company       Company          legal_name, rfc, address_line, ...
      $signer        User             representante legal que firma
      $signerTitle   string           puesto declarado por el firmante
      $terms         array            fee_kind, fee_value, warranty_days, jurisdiction, city
      $signatureSrc  string           data URI de la firma trazada por el cliente
      $humaeSignature array|null      opcional: ['src','name','title'] del apoderado HUMAE
      $logoSrc       string|null      opcional: data URI del logo HUMAE
      $evidence      array            ip, user_agent, accepted_at (trazabilidad de la firma)
--}}
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Contrato de prestación de servicios — {{ $company->legal_name }}</title>
@include('pdf.partials.contract-styles')
</head>
<body>

@php
    use Illuminate\Support\Str;

    $MONTHS = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    $signedAt = $contract->signed_at ?? now();

    // Fecha en letra, sin depender del locale de PHP/ICU en el servidor.
    $signedDateLong = sprintf(
        '%d de %s de %d',
        (int) $signedAt->format('j'),
        $MONTHS[(int) $signedAt->format('n')],
        (int) $signedAt->format('Y'),
    );

    $providerName = $terms['provider_name'] ?? 'Humae Consultoría de RH';
    $city = $terms['city'] ?? null;
    $jurisdiction = $terms['jurisdiction'] ?? null;
    $warrantyDays = $terms['warranty_days'] ?? null;

    // Los honorarios se negocian por empresa: porcentaje del sueldo bruto anualizado,
    // un múltiplo de sueldo mensual, o un monto fijo. El servicio valida que exista
    // antes de emitir el contrato; aquí sólo se formatea.
    $feeKind = $terms['fee_kind'] ?? null;
    $feeValue = $terms['fee_value'] ?? null;

    $feeText = match ($feeKind) {
        'percentage_annual_gross' => 'el '.rtrim(rtrim(number_format((float) $feeValue, 2, '.', ','), '0'), '.')
            .'% (por ciento) del sueldo bruto anualizado del candidato contratado',
        'monthly_salary_multiple' => (((float) $feeValue === 1.0)
            ? 'el equivalente a un (1) mes de sueldo bruto'
            : 'el equivalente a '.rtrim(rtrim(number_format((float) $feeValue, 2, '.', ','), '0'), '.').' meses de sueldo bruto')
            .' del candidato contratado',
        'fixed_amount' => '$'.number_format((float) $feeValue, 2, '.', ',')
            .' MXN ('.($terms['fee_amount_words'] ?? 'según carta de honorarios').') por cada candidato contratado',
        default => null,
    };

    $paymentDays = (int) ($terms['payment_days'] ?? 5);
    $paymentDayKind = ($terms['payment_day_kind'] ?? 'habiles') === 'naturales' ? 'naturales' : 'hábiles';

    // El plazo se escribe en dígito y letra, como es costumbre en contratos mexicanos.
    $DAY_WORDS = [
        1 => 'un', 2 => 'dos', 3 => 'tres', 4 => 'cuatro', 5 => 'cinco',
        6 => 'seis', 7 => 'siete', 8 => 'ocho', 9 => 'nueve', 10 => 'diez',
        15 => 'quince', 20 => 'veinte', 30 => 'treinta',
    ];
    $paymentDaysWords = $DAY_WORDS[$paymentDays] ?? null;

    $signerName = trim((string) ($signer->name ?? ''));
    $companyName = $company->legal_name;

    // Fragmento del lugar de firma. Se arma aquí (con el valor escapado) porque
    // un `@if` pegado a la palabra anterior no lo compila Blade, y separarlo con
    // espacios dejaría un espacio suelto antes de la coma cuando no hay ciudad.
    $placeFragment = ($city !== null && $city !== '')
        ? ' en la ciudad de <span class="bound">'.e($city).'</span>'
        : '';
@endphp

<div class="header">
    <table>
        <tr>
            <td>
                <div class="doc-kind">Contrato de prestación de servicios</div>
                <div class="folio">Folio {{ $contract->folio }}</div>
            </td>
            @if (! empty($logoSrc))
                <td class="logo"><img src="{{ $logoSrc }}" alt="HUMAE"></td>
            @endif
        </tr>
    </table>
</div>

<h1 class="contract-title">
    CONTRATO DE PRESTACIÓN DE SERVICIOS DE HUMAE<br>
    RECLUTAMIENTO, SELECCIÓN Y CONTRATACIÓN (ACCESO A PLATAFORMA)
</h1>

<div class="parties">
    <p>Conste por el presente documento el Contrato de Prestación de Servicios que celebran:</p>

    <p>
        Por una parte, <span class="bound">{{ $providerName }}</span>, a quien en lo sucesivo se le
        denominará <strong>&ldquo;EL PRESTADOR&rdquo;</strong>.
    </p>

    <p>
        Por la otra parte, <span class="bound">{{ $companyName }}</span>@if ($company->rfc), con Registro
        Federal de Contribuyentes <span class="bound">{{ $company->rfc }}</span>@endif, representada en este
        acto por <span class="bound">{{ $signerName }}</span> en su calidad de
        <span class="bound">{{ $signerTitle }}</span>, a quien en lo sucesivo se le denominará
        <strong>&ldquo;EL CLIENTE&rdquo;</strong>.
    </p>

    <p>
        Ambas partes se reconocen la capacidad legal para contratar y obligarse conforme a las siguientes:
    </p>
</div>

<div class="clauses-title">Cláusulas</div>

<div class="clause">
    <div class="clause-header">Primera: Objeto del contrato y titularidad de los candidatos</div>
    <p>
        El presente contrato tiene por objeto regular el acceso de EL CLIENTE a la plataforma digital de
        candidatos de {{ $providerName }}.
    </p>
    <p>
        Las partes acuerdan y reconocen expresamente que todos los candidatos registrados, perfiles
        visualizados y la base de datos en su totalidad son propiedad exclusiva de {{ $providerName }}.
    </p>
    <p>
        <span class="clause-term">Condición de Contacto:</span> Queda estrictamente prohibido que EL CLIENTE
        contacte de forma directa, externa o por medios ajenos a la plataforma a cualquier candidato sin que
        previamente se haya formalizado y firmado el presente contrato de servicios.
    </p>
</div>

<div class="clause">
    <div class="clause-header">Segunda: Seguimiento y estatus de entrevistas</div>
    <p>
        EL CLIENTE se obliga a mantener permanentemente informado a {{ $providerName }} sobre el avance del
        proceso de selección. Deberá notificar de manera obligatoria y oportuna el estatus de las entrevistas
        de cada uno de los candidatos seleccionados de la plataforma (fechas de entrevista, retroalimentación,
        si avanza a la siguiente etapa o si es descartado).
    </p>
</div>

<div class="clause">
    <div class="clause-header">Tercera: De los honorarios por contratación</div>
    <p>
        Al momento en que EL CLIENTE seleccione a un candidato de la plataforma para su contratación formal,
        se generará la obligación de pago por el servicio de reclutamiento.
    </p>
    <p>
        El monto a pagar por cada candidato contratado será de: <span class="bound">{{ $feeText }}</span>.
    </p>
</div>

<div class="clause">
    <div class="clause-header">Cuarta: Condiciones y plazo de pago</div>
    <p>
        EL CLIENTE se compromete y obliga a liquidar el total del monto estipulado por el servicio dentro de
        los primeros <span class="bound">{{ $paymentDays }}@if ($paymentDaysWords) ({{ $paymentDaysWords }})@endif</span>
        días {{ $paymentDayKind }} contados a partir de la fecha oficial de contratación o de ingreso del
        candidato seleccionado a la empresa.
    </p>
</div>

<div class="clause">
    <div class="clause-header">Quinta: Garantía de contratación</div>
    <p>
        {{ $providerName }} otorga a EL CLIENTE una garantía de sustitución del candidato bajo las siguientes
        condiciones:
    </p>
    <p>
        <span class="clause-term">Temporalidad:</span> La garantía tendrá una vigencia de
        <span class="bound">{{ $warrantyDays }}</span> días naturales, contados a partir del primer día de
        labores del candidato.
    </p>
    <p>
        <span class="clause-term">Aplicación:</span> Si dentro de este periodo el candidato decide renunciar
        voluntariamente o es separado de su puesto por causas imputables a su desempeño, {{ $providerName }}
        se compromete a reactivar la búsqueda y presentar una terna de sustitución sin costo adicional para
        EL CLIENTE.
    </p>
    <p>
        <span class="clause-term">Condición:</span> Esta garantía solo será válida si EL CLIENTE cumplió en
        tiempo y forma con el pago del servicio estipulado en la Cláusula Cuarta.
    </p>
</div>

<div class="clause">
    <div class="clause-header">Sexta: Confidencialidad</div>
    <p>
        Toda la información de los candidatos es confidencial. EL CLIENTE no podrá transferir, vender o
        compartir los datos de los candidatos con terceras personas o empresas filiales sin la autorización
        expresa de {{ $providerName }}.
    </p>
</div>

<div class="clause">
    <div class="clause-header">Séptima: Legislación y jurisdicción</div>
    <p>
        Para la interpretación y cumplimiento del presente contrato, las partes se someten a las leyes y
        tribunales de <span class="bound">{{ $jurisdiction }}</span>, renunciando a cualquier otro fuero.
    </p>
</div>

<div class="closing">
    <p>
        Leído el presente documento por ambas partes y enteradas de su contenido y alcance legal, lo firman
        por duplicado{!! $placeFragment !!}, el día
        <span class="bound">{{ $signedDateLong }}</span>, mediante firma electrónica en la plataforma HUMAE.
    </p>
</div>

@include('pdf.partials.contract-signatures')

@include('pdf.partials.contract-evidence')

<div class="footer">
    {{ $providerName }} &middot; Contrato {{ $contract->folio }} &middot; {{ $companyName }}
</div>

</body>
</html>
