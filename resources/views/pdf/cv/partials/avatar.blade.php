{{--
    Contenido del avatar: la foto del candidato, o sus iniciales si no cargó una.

    Las iniciales se centran con una tabla y vertical-align: middle. DomPDF no
    centra una línea suelta dentro de un line-height alto como sí hace el
    navegador: apoya la base cerca del borde inferior. Con overflow: hidden en
    el círculo, las iniciales quedaban cortadas y una J se leía como una I.

    El tamaño de letra y el color los pone el contenedor de cada plantilla.

    @param  string   $initials
    @param  ?string  $src   Foto como data URI, si existe.
    @param  int      $size  Lado del círculo en píxeles.
--}}
@if (! empty($src))
    <img src="{{ $src }}" alt="" style="width: {{ $size }}px; height: {{ $size }}px; display: block;" />
@else
    <table style="width: {{ $size }}px; height: {{ $size }}px; border-collapse: collapse;">
        <tr style="height: {{ $size }}px;">
            <td style="height: {{ $size }}px; text-align: center; vertical-align: middle; padding: 0; line-height: 1;">
                {{ $initials !== '' ? $initials : '?' }}
            </td>
        </tr>
    </table>
@endif
