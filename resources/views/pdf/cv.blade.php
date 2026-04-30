<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>CV — {{ $profile->first_name }} {{ $profile->last_name }}</title>
    <style>
        @page { margin: 28px 32px 36px 32px; }

        * { box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10.5px;
            color: #081828;
            margin: 0;
        }

        .header {
            border-bottom: 2px solid #314259;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .header table { width: 100%; border-collapse: collapse; }
        .header .name { font-size: 22px; font-weight: bold; color: #081828; }
        .header .headline {
            font-size: 12px;
            color: #314259;
            margin-top: 2px;
        }
        .header .contact {
            font-size: 9.5px;
            color: #6b7280;
            margin-top: 6px;
            line-height: 1.5;
        }
        .header .logo { text-align: right; vertical-align: top; }
        .header .logo img { width: 80px; }

        .header .avatar { width: 76px; vertical-align: top; padding-right: 14px; }
        .header .avatar-frame {
            width: 70px;
            height: 70px;
            border-radius: 35px;
            background: #e5e7eb;
            border: 2px solid #314259;
            overflow: hidden;
            text-align: center;
            line-height: 66px;
            color: #314259;
            font-weight: bold;
            font-size: 22px;
        }
        .header .avatar-frame img {
            width: 70px;
            height: 70px;
            display: block;
        }

        .section {
            margin-bottom: 14px;
            page-break-inside: avoid;
        }
        .section-title {
            font-size: 11.5px;
            font-weight: bold;
            color: #314259;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 3px;
            margin-bottom: 8px;
        }

        .summary { line-height: 1.5; color: #374151; }

        .item {
            margin-bottom: 9px;
            page-break-inside: avoid;
        }
        .item-head { font-size: 10.5px; font-weight: bold; color: #081828; }
        .item-sub { font-size: 9.5px; color: #374151; margin-top: 1px; }
        .item-dates { font-size: 9px; color: #6b7280; margin-top: 1px; }
        .item-desc { font-size: 9.5px; color: #374151; margin-top: 3px; line-height: 1.4; }

        .two-col { width: 100%; border-collapse: collapse; }
        .two-col td {
            vertical-align: top;
            width: 50%;
            padding-right: 10px;
        }

        .pill {
            display: inline-block;
            background: #e5e7eb;
            color: #314259;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            margin: 0 3px 3px 0;
        }

        .footer {
            position: fixed;
            bottom: -22px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8px;
            color: #9ca3af;
        }
    </style>
</head>
<body>

@php
    $fullName = trim(($profile->first_name ?? '') . ' ' . ($profile->last_name ?? ''));
    $contactPieces = array_filter([
        $profile->contact_email ?? $user->email,
        $profile->contact_phone,
        $profile->linkedin_url,
        $profile->portfolio_url,
    ]);

    $initials = '';
    foreach (preg_split('/\s+/', trim($fullName !== '' ? $fullName : (string) $user->name)) ?: [] as $part) {
        if ($part !== '' && mb_strlen($initials) < 2) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }
@endphp

<div class="header">
    <table>
        <tr>
            <td class="avatar">
                <div class="avatar-frame">
                    @if (! empty($avatarSrc))
                        <img src="{{ $avatarSrc }}" alt="" />
                    @else
                        {{ $initials !== '' ? $initials : '?' }}
                    @endif
                </div>
            </td>
            <td>
                <div class="name">{{ $fullName ?: $user->name }}</div>
                @if ($profile->headline)
                    <div class="headline">{{ $profile->headline }}</div>
                @endif
                <div class="contact">
                    {!! implode(' &nbsp;·&nbsp; ', $contactPieces) !!}
                </div>
            </td>
            <td class="logo">
                @if (file_exists($logoPath))
                    <img src="{{ $logoPath }}" alt="HUMAE" />
                @else
                    <strong style="color:#314259;">HUMAE</strong>
                @endif
            </td>
        </tr>
    </table>
</div>

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

<table class="two-col">
    <tr>
        <td>
            @if ($profile->skills->isNotEmpty())
                <div class="section">
                    <div class="section-title">Habilidades</div>
                    @foreach ($profile->skills as $skill)
                        <span class="pill">{{ $skill->name }}@if ($skill->pivot?->level) · {{ $skill->pivot->level }}@endif</span>
                    @endforeach
                </div>
            @endif

            @if ($profile->languages->isNotEmpty())
                <div class="section">
                    <div class="section-title">Idiomas</div>
                    @foreach ($profile->languages as $lang)
                        <span class="pill">{{ $lang->name }}@if ($lang->pivot?->level) · {{ strtoupper((string) $lang->pivot->level) }}@endif</span>
                    @endforeach
                </div>
            @endif
        </td>
        <td>
            @if ($profile->certifications->isNotEmpty())
                <div class="section">
                    <div class="section-title">Certificaciones</div>
                    @foreach ($profile->certifications as $cert)
                        <div class="item">
                            <div class="item-head">{{ $cert->name }}</div>
                            <div class="item-sub">{{ $cert->issuer }}</div>
                            <div class="item-dates">
                                {{ optional($cert->issued_at)->translatedFormat('M Y') ?? '' }}
                            </div>
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
        </td>
    </tr>
</table>

<div class="footer">
    Generado por HUMAE · {{ $generatedAt->translatedFormat('d \\d\\e F \\d\\e Y') }}
</div>

</body>
</html>
