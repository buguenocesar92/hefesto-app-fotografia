<?php

declare(strict_types=1);

use App\Enums\RolEstudio;

/*
|------------------------------------------------------------------------------
| Sesión web de Laravel
|------------------------------------------------------------------------------
|
| POR QUÉ LA TABLA SE LLAMA `sesion_web` Y NO `sesiones`
| -----------------------------------------------------
| En el dominio de la fotografía, `sesiones` son las tomas con fecha prometida:
| el cumple, el matrimonio, el bautismo. Es la palabra que usa el estudio y la
| que aparece en todos los informal de las pantallas.
|
| La tabla de sesión de Laravel no tiene por qué llamarse igual. Se le da otro
| nombre y las dos pueden usar el suyo sin ambigüedad. Esta tabla NO lleva Row
| Level Security: se escribe antes de que exista el estudio de la petición y se
| lee en cada autenticación. Ver docs/02 y docs/03.
|
*/

return [

    'driver' => env('SESSION_DRIVER', 'database'),

    'lifetime' => (int) env('SESSION_EXPIRACION_MINUTOS', 120),

    'expire_on_close' => false,

    'encrypt' => false,

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION'),

    'table' => 'sesion_web',

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    'cookie' => env(
        'SESSION_COOKIE',
        'fotografia_sesion'
    ),

    'path' => '/',

    'domain' => env('SESSION_DOMAIN'),

    'secure' => (bool) env('SESSION_SECURE_COOKIE', false),

    'http_only' => true,

    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    'partitioned' => false,

];