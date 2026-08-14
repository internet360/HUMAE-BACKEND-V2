<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Vigencia del enlace a la foto del candidato
    |--------------------------------------------------------------------------
    |
    | Minutos que dura firmado el enlace que la empresa recibe para ver la foto
    | de un perfil del directorio anónimo. Corto a propósito: el enlace no lleva
    | sesión —un `<img src>` no puede adjuntar el Bearer— así que la firma es la
    | única credencial, y lo que la acota es el tiempo.
    |
    | Tiene que sobrevivir a una sesión de navegación normal sin obligar a
    | recargar. Media hora cubre eso; más lo vuelve un enlace permanente.
    |
    */
    'photo_link_minutes' => env('DIRECTORY_PHOTO_LINK_MINUTES', 30),
];
