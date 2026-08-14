<!DOCTYPE html>
{{--
    Adenda de honorarios para UNA vacante — empresa cliente.

    Existe como plantilla propia y no como variante del contrato maestro porque
    los dos documentos dicen cosas distintas. La adenda reutilizaba el Blade del
    maestro y salía titulada «acceso a plataforma», con una cláusula Primera que
    regulaba un acceso ya pactado: un documento que no nombra su objeto ni su
    antecedente no es una adenda, es una copia con otro número.

    Lo que sí se comparte va en `pdf/partials/`: estilos, bloque de firmas y
    constancia. El instrumento se sella igual y vale lo mismo; lo que cambia es
    el texto.

    Variables esperadas:
      $contract        CompanyContract  folio, signed_at, terms (con el honorario de la vacante)
      $company         Company
      $signer          User             representante que firma
      $signerTitle     string
      $terms           array            fee_kind, fee_value, provider_name, city, jurisdiction…
      $vacancy         Vacancy|null     la vacante que esta adenda gobierna
      $masterContract  CompanyContract|null  el contrato al que accede; null sólo si se anuló
      $signatureSrc    string|null      data URI de la firma trazada; null en el borrador
      $humaeSignature  array|null
      $logoSrc         string|null
      $evidence        array
--}}
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Adenda de honorarios &mdash; {{ $company->legal_name }}</title>
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

    $longDate = static function ($date) use ($MONTHS): string {
        return sprintf(
            '%d de %s de %d',
            (int) $date->format('j'),
            $MONTHS[(int) $date->format('n')],
            (int) $date->format('Y'),
        );
    };

    $signedAt = $contract->signed_at ?? now();
    $signedDateLong = $longDate($signedAt);

    $providerName = $terms['provider_name'] ?? 'Humae Consultoría de RH';
    $city = $terms['city'] ?? null;
    $jurisdiction = $terms['jurisdiction'] ?? null;

    $feeKind = $terms['fee_kind'] ?? null;
    $feeValue = $terms['fee_value'] ?? null;

    // Mismo vocabulario que el contrato maestro: si los dos documentos nombran
    // el honorario distinto, discutir cuál aplica se vuelve un ejercicio de
    // interpretación.
    $feeText = match ($feeKind) {
        'percentage_annual_gross' => 'el '.rtrim(rtrim(number_format((float) $feeValue, 2, '.', ','), '0'), '.')
            .'% (por ciento) del sueldo bruto anualizado del candidato contratado',
        'monthly_salary_multiple' => (((float) $feeValue === 1.0)
            ? 'el equivalente a un (1) mes de sueldo bruto'
            : 'el equivalente a '.rtrim(rtrim(number_format((float) $feeValue, 2, '.', ','), '0'), '.').' meses de sueldo bruto')
            .' del candidato contratado',
        'fixed_amount' => '$'.number_format((float) $feeValue, 2, '.', ',')
            .' MXN ('.($terms['fee_amount_words'] ?? 'según carta de honorarios').') por el candidato contratado',
        default => null,
    };

    $signerName = trim((string) ($signer->name ?? ''));
    $companyName = $company->legal_name;

    $vacancyTitle = $vacancy->title ?? null;
    $vacancyCode = $vacancy->code ?? null;

    $masterFolio = $masterContract->folio ?? null;
    $masterDateLong = ($masterContract?->signed_at !== null)
        ? $longDate($masterContract->signed_at)
        : null;

    $placeFragment = ($city !== null && $city !== '')
        ? ' en la ciudad de <span class="bound">'.e($city).'</span>'
        : '';

    /*
        Los fragmentos condicionales se arman acá y no con `@if` en línea.
        Blade no compila una directiva pegada a otra —`@endif@if`— y separarlas
        con espacios metería un espacio suelto antes de la coma. Es la misma
        razón que ya documenta el contrato maestro para `$placeFragment`.
    */
    $vacancyTitleLine = $vacancyTitle !== null
        ? '<br>VACANTE &ldquo;'.e(Str::upper($vacancyTitle)).'&rdquo;'
            .($vacancyCode !== null ? ' ('.e($vacancyCode).')' : '')
        : '';

    $masterReference = '';
    if ($masterFolio !== null) {
        $masterReference .= ', identificado con el folio <span class="bound">'.e($masterFolio).'</span>';
    }
    if ($masterDateLong !== null) {
        $masterReference .= ', suscrito el <span class="bound">'.e($masterDateLong).'</span>';
    }

    $rfcFragment = ($company->rfc !== null && $company->rfc !== '')
        ? ', con Registro Federal de Contribuyentes <span class="bound">'.e($company->rfc).'</span>'
        : '';

    $footerVacancy = $vacancyCode !== null
        ? ' &middot; Vacante '.e($vacancyCode)
        : '';
@endphp

<div class="header">
    <table>
        <tr>
            <td>
                <div class="doc-kind">Adenda de honorarios por vacante</div>
                <div class="folio">Folio {{ $contract->folio }}</div>
            </td>
            @if (! empty($logoSrc))
                <td class="logo"><img src="{{ $logoSrc }}" alt="HUMAE"></td>
            @endif
        </tr>
    </table>
</div>

{{--
    El título nombra las tres cosas que distinguen a este documento: que es una
    adenda, que es de honorarios y de qué vacante. Quien lo abra dentro de dos
    años tiene que saberlo sin leer las cláusulas.
--}}
<h1 class="contract-title">
    ADENDA DE HONORARIOS AL CONTRATO DE PRESTACIÓN DE SERVICIOS{!! $vacancyTitleLine !!}
</h1>

<div class="parties">
    <p>
        Conste por el presente documento la Adenda de Honorarios al Contrato de Prestación de Servicios que
        celebran:
    </p>

    <p>
        Por una parte, <span class="bound">{{ $providerName }}</span>, a quien en lo sucesivo se le
        denominará <strong>&ldquo;EL PRESTADOR&rdquo;</strong>.
    </p>

    <p>
        Por la otra parte, <span class="bound">{{ $companyName }}</span>{!! $rfcFragment !!}, representada en
        este acto por <span class="bound">{{ $signerName }}</span> en su calidad de
        <span class="bound">{{ $signerTitle }}</span>, a quien en lo sucesivo se le denominará
        <strong>&ldquo;EL CLIENTE&rdquo;</strong>.
    </p>
</div>

{{--
    Los antecedentes son lo que convierte esto en una adenda y no en un contrato
    suelto. Sin nombrar el instrumento al que accede, el documento queda huérfano
    y su alcance es discutible.
--}}
<div class="clauses-title">Antecedentes</div>

<div class="clause">
    <p>
        <span class="clause-term">Único.</span> Las partes tienen celebrado un
        <strong>Contrato de Prestación de Servicios de Reclutamiento, Selección y Contratación (Acceso a
        Plataforma)</strong>{!! $masterReference !!}, en lo sucesivo <strong>&ldquo;EL CONTRATO&rdquo;</strong>,
        cuyas cláusulas se encuentran vigentes y son plenamente reconocidas por ambas partes.
    </p>
    <p>
        En la cláusula Tercera de EL CONTRATO se pactaron los honorarios generales aplicables a toda
        contratación derivada de la plataforma. Las partes desean fijar honorarios distintos, aplicables
        exclusivamente a la vacante que se identifica en la cláusula Primera de esta adenda, conforme a las
        siguientes:
    </p>
</div>

<div class="clauses-title">Cláusulas</div>

<div class="clause">
    <div class="clause-header">Primera: Objeto y vacante a la que aplica</div>
    <p>
        La presente adenda tiene por objeto <strong>modificar únicamente los honorarios</strong> que EL
        CLIENTE pagará a EL PRESTADOR por la contratación de un candidato para la siguiente vacante:
    </p>

    <table class="evidence-table" style="width:100%; border-collapse:collapse; margin:8px 0 10px 0;">
        <tr>
            <td style="width:32%; padding:4px 8px 4px 0; color:#6b7280;">Vacante</td>
            <td style="padding:4px 0;"><span class="bound">{{ $vacancyTitle ?? 'Por identificar' }}</span></td>
        </tr>
        @if ($vacancyCode)
            <tr>
                <td style="padding:4px 8px 4px 0; color:#6b7280;">Folio de la vacante</td>
                <td style="padding:4px 0;"><span class="bound">{{ $vacancyCode }}</span></td>
            </tr>
        @endif
        <tr>
            <td style="padding:4px 8px 4px 0; color:#6b7280;">Empresa solicitante</td>
            <td style="padding:4px 0;">{{ $companyName }}</td>
        </tr>
    </table>

    <p>
        <span class="clause-term">Alcance:</span> Esta adenda <strong>no aplica a ninguna otra vacante</strong>
        de EL CLIENTE, presente o futura, ni a contrataciones derivadas de procesos distintos al aquí
        identificado. Cualquier otra contratación se regirá por los honorarios pactados en EL CONTRATO.
    </p>
</div>

<div class="clause">
    <div class="clause-header">Segunda: Honorarios aplicables a esta vacante</div>
    @if ($feeText)
        <p>
            Por la contratación de un candidato presentado por EL PRESTADOR para la vacante señalada en la
            cláusula Primera, EL CLIENTE pagará a EL PRESTADOR
            <span class="bound">{{ $feeText }}</span>, en sustitución del honorario general previsto en la
            cláusula Tercera de EL CONTRATO.
        </p>
    @else
        <p>
            Los honorarios aplicables a esta vacante se detallan en la carta de honorarios que forma parte
            integrante de la presente adenda.
        </p>
    @endif
    <p>
        <span class="clause-term">Devengo:</span> El honorario se genera al momento en que EL CLIENTE
        formaliza la contratación del candidato, y se calcula sobre el sueldo final acordado entre EL CLIENTE
        y dicho candidato.
    </p>
</div>

<div class="clause">
    <div class="clause-header">Tercera: Subsistencia de EL CONTRATO</div>
    <p>
        Salvo por el honorario expresamente modificado en la cláusula Segunda y sólo respecto de la vacante
        identificada en la cláusula Primera, <strong>todas las demás estipulaciones de EL CONTRATO
        permanecen en sus términos</strong> y continúan obligando a las partes. De manera enunciativa y no
        limitativa, subsisten sin cambio:
    </p>
    <p>
        la titularidad de EL PRESTADOR sobre los candidatos y la base de datos; la prohibición de contacto
        directo fuera de la plataforma; la obligación de informar el estatus de las entrevistas; las
        condiciones y el plazo de pago; la garantía de contratación; la confidencialidad; y la legislación y
        jurisdicción aplicables.
    </p>
    <p>
        <span class="clause-term">Prelación:</span> En caso de discrepancia entre esta adenda y EL CONTRATO
        respecto de los honorarios de la vacante señalada, prevalecerá lo dispuesto en esta adenda. En todo
        lo demás prevalecerá EL CONTRATO.
    </p>
</div>

<div class="clause">
    <div class="clause-header">Cuarta: Vigencia</div>
    <p>
        La presente adenda surte efectos a partir de la fecha de su firma electrónica y permanece vigente
        hasta que el proceso de selección de la vacante identificada se cierre, sea por contratación o por
        cancelación. Su terminación no afecta la vigencia de EL CONTRATO.
    </p>
    @if ($jurisdiction)
        <p>
            Para la interpretación y cumplimiento de esta adenda, las partes se someten a la legislación
            aplicable y a la jurisdicción de los tribunales de
            <span class="bound">{{ $jurisdiction }}</span>, en los mismos términos pactados en EL CONTRATO.
        </p>
    @endif
</div>

<div class="closing">
    <p>
        Leída la presente adenda por ambas partes y enteradas de su contenido y alcance legal, la firman
        por duplicado{!! $placeFragment !!}, el día
        <span class="bound">{{ $signedDateLong }}</span>, mediante firma electrónica en la plataforma HUMAE.
    </p>
</div>

@include('pdf.partials.contract-signatures')

@include('pdf.partials.contract-evidence')

<div class="footer">
    {{ $providerName }} &middot; Adenda {{ $contract->folio }} &middot; {{ $companyName }}{!! $footerVacancy !!}
</div>

</body>
</html>
