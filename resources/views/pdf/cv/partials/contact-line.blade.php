{{--
    Línea de datos de contacto del candidato.

    El separador lleva marcado, así que la salida va sin escapar. Por eso cada
    dato —que es texto libre editable por el candidato— se escapa por separado
    antes de unirlos. Este es el único lugar donde se arma la línea: si cada
    plantilla la repitiera, tarde o temprano una se olvidaría de escapar.

    @param  list<string>  $pieces
    @param  string        $separator  Marcado que une las piezas.
--}}
@php
    $separator = $separator ?? ' &nbsp;·&nbsp; ';
@endphp
{!! implode($separator, array_map(static fn (string $piece): string => e($piece), $pieces)) !!}
