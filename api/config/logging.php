<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/*
|------------------------------------------------------------------------------
| Registro
|------------------------------------------------------------------------------
|
| Dos canales con reglas distintas, y la diferencia importa en un estudio:
|
|   - `stack` es el que se usa en desarrollo.
|   - `stderr` escribe en la salida estándar del contenedor, que es lo que
|     `docker compose logs` muestra y lo que el despliegue recoge.
|
| EL NIVEL CUENTA MÁS QUE EL CANAL
| ---------------------------------
| Un registro con nivel `debug` puede incluir el cuerpo de la petición. En un
| estudio eso incluye el nombre de la persona de la que son las fotos que se
| están por entregar. En producción el nivel tiene que ser `info` o más alto.
| Ver docs/07.
|
*/

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single,stderr')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => (int) env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        /*
        | Canal sin archivo y sin salida: para cuando una petición necesita
        | registrar algo y no se quiere que eso llegue a ninguna parte. Existe
        | para que el código no tenga que inventar una ruta.
        */
        'null' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],

        'emergencia' => [
            'path' => storage_path('logs/emergencia.log'),
        ],

    ],

];