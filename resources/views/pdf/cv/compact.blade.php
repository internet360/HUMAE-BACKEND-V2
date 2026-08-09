<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>CV — {{ $cv->fullName }}</title>
    <style>
        @page { margin: 26px 34px 32px 34px; }

        * { box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9.5px;
            color: #081828;
            margin: 0;
            line-height: 1.35;
        }

        .head { margin-bottom: 10px; }
        .head table { width: 100%; border-collapse: collapse; }
        .head .name {
            font-size: 19px;
            font-weight: bold;
            letter-spacing: -0.2px;
            color: #081828;
        }
        .head .headline { font-size: 10.5px; color: #314259; margin-top: 1px; }
        .head .logo { text-align: right; vertical-align: top; width: 68px; }
        .head .logo img { width: 62px; }

        .contact {
            font-size: 8.5px;
            color: #6b7280;
            margin-top: 5px;
            padding-top: 5px;
            border-top: 1px solid #e5e7eb;
            line-height: 1.5;
        }

        .rule { border-bottom: 1.5px solid #314259; margin-bottom: 11px; }

        .section { margin-bottom: 10px; }
        .section-title {
            font-size: 9.5px;
            font-weight: bold;
            color: #314259;
            text-transform: uppercase;
            letter-spacing: 0.9px;
            margin-bottom: 5px;
        }

        .summary { color: #374151; }

        /*
         * Cada item es una fila de dos celdas: contenido a la izquierda y
         * fechas alineadas a la derecha. Gana una línea por entrada frente a
         * poner las fechas debajo, que es de lo que se trata esta plantilla.
         */
        .item { width: 100%; border-collapse: collapse; margin-bottom: 6px; page-break-inside: avoid; }
        .item td { vertical-align: top; padding: 0; }
        .item .dates {
            text-align: right;
            white-space: nowrap;
            font-size: 8.5px;
            color: #6b7280;
            width: 108px;
            padding-left: 10px;
        }
        .item-head { font-size: 9.5px; font-weight: bold; color: #081828; }
        .item-sub { font-size: 9px; color: #374151; }
        .item-desc { font-size: 9px; color: #4b5563; margin-top: 2px; }

        .inline-list { color: #374151; font-size: 9px; }

        .footer {
            position: fixed;
            bottom: -20px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 7.5px;
            color: #9ca3af;
        }

        /* Sólo para la vista previa; ver la nota en classic.blade.php. */
        @media screen {
            body { padding: 26px 34px 32px 34px; }
            .footer { bottom: 10px; }
        }
    </style>
</head>
<body>

@php
    $profile = $cv->profile;

    $skillList = $profile->skills
        ->map(fn ($skill) => $skill->name.($skill->pivot?->level ? ' ('.$skill->pivot->level.')' : ''))
        ->all();

    $languageList = $profile->languages
        ->map(fn ($lang) => $lang->name.($lang->pivot?->level ? ' ('.strtoupper((string) $lang->pivot->level).')' : ''))
        ->all();
@endphp

<div class="head">
    <table>
        <tr>
            <td>
                <div class="name">{{ $cv->fullName }}</div>
                @if ($profile->headline)
                    <div class="headline">{{ $profile->headline }}</div>
                @endif
            </td>
            <td class="logo">
                @if ($cv->logoSrc !== null)
                    <img src="{{ $cv->logoSrc }}" alt="HUMAE" />
                @else
                    <strong style="color:#314259;">HUMAE</strong>
                @endif
            </td>
        </tr>
    </table>

    @if ($cv->contactPieces !== [])
        <div class="contact">
            @include('pdf.cv.partials.contact-line', ['pieces' => $cv->contactPieces])
        </div>
    @endif
</div>

<div class="rule"></div>

@if ($profile->summary)
    <div class="section">
        <div class="section-title">Resumen profesional</div>
        <div class="summary">{{ $profile->summary }}</div>
    </div>
@endif

@if ($profile->experiences->isNotEmpty())
    <div class="section">
        <div class="section-title">Experiencia laboral</div>
        @foreach ($profile->experiences as $exp)
            <table class="item">
                <tr>
                    <td>
                        <div class="item-head">{{ $exp->position_title }} · {{ $exp->company_name }}</div>
                        @if ($exp->location)
                            <div class="item-sub">{{ $exp->location }}</div>
                        @endif
                        @if ($exp->description)
                            <div class="item-desc">{{ $exp->description }}</div>
                        @endif
                    </td>
                    <td class="dates">
                        {{ optional($exp->start_date)->translatedFormat('M Y') ?? '—' }} —
                        {{ $exp->is_current ? 'Actual' : (optional($exp->end_date)->translatedFormat('M Y') ?? '—') }}
                    </td>
                </tr>
            </table>
        @endforeach
    </div>
@endif

@if ($profile->educations->isNotEmpty())
    <div class="section">
        <div class="section-title">Educación</div>
        @foreach ($profile->educations as $edu)
            <table class="item">
                <tr>
                    <td>
                        <div class="item-head">{{ $edu->institution }}</div>
                        @if ($edu->field_of_study)
                            <div class="item-sub">{{ $edu->field_of_study }}{{ $edu->status ? ' — '.$edu->status : '' }}</div>
                        @endif
                    </td>
                    <td class="dates">
                        {{ optional($edu->start_date)->translatedFormat('Y') ?? '' }}
                        @if ($edu->end_date || $edu->is_current)
                            —
                            {{ $edu->is_current ? 'En curso' : (optional($edu->end_date)->translatedFormat('Y') ?? '') }}
                        @endif
                    </td>
                </tr>
            </table>
        @endforeach
    </div>
@endif

@if ($profile->certifications->isNotEmpty())
    <div class="section">
        <div class="section-title">Certificaciones</div>
        @foreach ($profile->certifications as $cert)
            <table class="item">
                <tr>
                    <td>
                        <div class="item-head">{{ $cert->name }}</div>
                        <div class="item-sub">{{ $cert->issuer }}</div>
                    </td>
                    <td class="dates">{{ optional($cert->issued_at)->translatedFormat('M Y') ?? '' }}</td>
                </tr>
            </table>
        @endforeach
    </div>
@endif

@if ($profile->courses->isNotEmpty())
    <div class="section">
        <div class="section-title">Cursos</div>
        @foreach ($profile->courses as $course)
            <table class="item">
                <tr>
                    <td>
                        <div class="item-head">{{ $course->name }}</div>
                        @if ($course->institution)
                            <div class="item-sub">{{ $course->institution }}</div>
                        @endif
                    </td>
                    <td class="dates">{{ optional($course->completed_at)->translatedFormat('M Y') ?? '' }}</td>
                </tr>
            </table>
        @endforeach
    </div>
@endif

@if ($skillList !== [])
    <div class="section">
        <div class="section-title">Habilidades</div>
        <div class="inline-list">{{ implode(' · ', $skillList) }}</div>
    </div>
@endif

@if ($languageList !== [])
    <div class="section">
        <div class="section-title">Idiomas</div>
        <div class="inline-list">{{ implode(' · ', $languageList) }}</div>
    </div>
@endif

<div class="footer">
    Generado por HUMAE · {{ $cv->generatedAt->translatedFormat('d \\d\\e F \\d\\e Y') }}
</div>

</body>
</html>
