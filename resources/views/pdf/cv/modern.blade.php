<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>CV — {{ $cv->fullName }}</title>
    <style>
        /* Sin margen de página: la banda lateral tiene que llegar al borde. */
        @page { margin: 0; }

        * { box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10.5px;
            color: #081828;
            margin: 0;
        }

        /*
         * El sidebar va fijo, no como celda de una tabla. DomPDF no puede
         * partir una fila de tabla más alta que la página: reserva hojas en
         * blanco y vuelca todo al final, desbordado. Fijo se repite en cada
         * página y se pinta por encima del flujo, que acá no molesta porque
         * .main arranca a la derecha de la banda.
         */
        .side {
            position: fixed;
            top: 0;
            left: 0;
            width: 200px;
            height: 100%;
            background: #314259;
            color: #ffffff;
        }

        /*
         * El relleno va en un envoltorio y no en .side: DomPDF no aplica
         * box-sizing: border-box acá, así que el padding se sumaría al ancho
         * y la banda taparía el arranque del contenido principal.
         */
        .side-inner { padding: 26px 18px 26px 20px; }

        /* El padding horizontal sí se mantiene a través de los saltos. */
        .main { padding: 30px 30px 46px 222px; }

        .avatar-frame {
            width: 92px;
            height: 92px;
            border-radius: 46px;
            background: #41556f;
            overflow: hidden;
            color: #ffffff;
            font-weight: bold;
            font-size: 28px;
            margin-bottom: 18px;
        }

        .side-title {
            font-size: 9.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #b9c4d2;
            border-bottom: 1px solid #4a5c74;
            padding-bottom: 3px;
            margin: 0 0 7px 0;
        }
        .side-block { margin-bottom: 16px; page-break-inside: avoid; }
        .side-contact { font-size: 9px; line-height: 1.7; color: #e6ebf1; }

        .side-pill {
            display: inline-block;
            background: #41556f;
            color: #ffffff;
            padding: 2px 7px;
            border-radius: 9px;
            font-size: 8.5px;
            margin: 0 3px 4px 0;
        }

        .name { font-size: 25px; font-weight: bold; color: #081828; line-height: 1.15; }
        .headline { font-size: 12px; color: #314259; margin-top: 3px; }
        .name-rule { border-bottom: 2px solid #314259; margin: 12px 0 16px 0; }

        .logo { text-align: right; }
        .logo img { width: 74px; }

        .section { margin-bottom: 15px; }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #314259;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 7px;
        }

        .summary { line-height: 1.55; color: #374151; }

        .item { margin-bottom: 10px; page-break-inside: avoid; }
        .item-head { font-size: 10.5px; font-weight: bold; color: #081828; }
        .item-sub { font-size: 9.5px; color: #374151; margin-top: 1px; }
        .item-dates { font-size: 9px; color: #6b7280; margin-top: 1px; }
        .item-desc { font-size: 9.5px; color: #374151; margin-top: 3px; line-height: 1.45; }

        .footer {
            position: fixed;
            bottom: 14px;
            left: 220px;
            right: 30px;
            text-align: right;
            font-size: 8px;
            color: #9ca3af;
        }
    </style>
</head>
<body>

@php
    $profile = $cv->profile;
@endphp

<div class="side">
    <div class="side-inner">
        <div class="avatar-frame">
            @include('pdf.cv.partials.avatar', [
                'initials' => $cv->initials,
                'src' => $cv->avatarSrc,
                'size' => 92,
            ])
        </div>

        @if ($cv->contactPieces !== [])
            <div class="side-block">
                <div class="side-title">Contacto</div>
                <div class="side-contact">
                    @include('pdf.cv.partials.contact-line', [
                        'pieces' => $cv->contactPieces,
                        'separator' => '<br />',
                    ])
                </div>
            </div>
        @endif

        @if ($profile->skills->isNotEmpty())
            <div class="side-block">
                <div class="side-title">Habilidades</div>
                {{-- Sin el nivel: la columna es angosta y un pill que se parte
                     en dos líneas rompe el fondo redondeado. El nivel sí va en
                     las plantillas de una columna. --}}
                @foreach ($profile->skills as $skill)
                    <span class="side-pill">{{ $skill->name }}</span>
                @endforeach
            </div>
        @endif

        @if ($profile->languages->isNotEmpty())
            <div class="side-block">
                <div class="side-title">Idiomas</div>
                @foreach ($profile->languages as $lang)
                    <span class="side-pill">{{ $lang->name }}@if ($lang->pivot?->level) · {{ strtoupper((string) $lang->pivot->level) }}@endif</span>
                @endforeach
            </div>
        @endif
    </div>
</div>

<div class="main">
    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="vertical-align:top;">
                <div class="name">{{ $cv->fullName }}</div>
                @if ($profile->headline)
                    <div class="headline">{{ $profile->headline }}</div>
                @endif
            </td>
            <td class="logo" style="width:84px; vertical-align:top;">
                @if ($cv->logoSrc !== null)
                    <img src="{{ $cv->logoSrc }}" alt="HUMAE" />
                @else
                    <strong style="color:#314259;">HUMAE</strong>
                @endif
            </td>
        </tr>
    </table>

    <div class="name-rule"></div>

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
                <div class="item">
                    <div class="item-head">{{ $exp->position_title }} · {{ $exp->company_name }}</div>
                    @if ($exp->location)
                        <div class="item-sub">{{ $exp->location }}</div>
                    @endif
                    <div class="item-dates">
                        {{ optional($exp->start_date)->translatedFormat('M Y') ?? '—' }} —
                        {{ $exp->is_current ? 'Actual' : (optional($exp->end_date)->translatedFormat('M Y') ?? '—') }}
                    </div>
                    @if ($exp->description)
                        <div class="item-desc">{{ $exp->description }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($profile->educations->isNotEmpty())
        <div class="section">
            <div class="section-title">Educación</div>
            @foreach ($profile->educations as $edu)
                <div class="item">
                    <div class="item-head">{{ $edu->institution }}</div>
                    @if ($edu->field_of_study)
                        <div class="item-sub">{{ $edu->field_of_study }}{{ $edu->status ? ' — '.$edu->status : '' }}</div>
                    @endif
                    <div class="item-dates">
                        {{ optional($edu->start_date)->translatedFormat('Y') ?? '' }}
                        @if ($edu->end_date || $edu->is_current)
                            —
                            {{ $edu->is_current ? 'En curso' : (optional($edu->end_date)->translatedFormat('Y') ?? '') }}
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($profile->certifications->isNotEmpty())
        <div class="section">
            <div class="section-title">Certificaciones</div>
            @foreach ($profile->certifications as $cert)
                <div class="item">
                    <div class="item-head">{{ $cert->name }}</div>
                    <div class="item-sub">{{ $cert->issuer }}</div>
                    <div class="item-dates">{{ optional($cert->issued_at)->translatedFormat('M Y') ?? '' }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($profile->courses->isNotEmpty())
        <div class="section">
            <div class="section-title">Cursos</div>
            @foreach ($profile->courses as $course)
                <div class="item">
                    <div class="item-head">{{ $course->name }}</div>
                    @if ($course->institution)
                        <div class="item-sub">{{ $course->institution }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>

<div class="footer">
    Generado por HUMAE · {{ $cv->generatedAt->translatedFormat('d \\d\\e F \\d\\e Y') }}
</div>

</body>
</html>
