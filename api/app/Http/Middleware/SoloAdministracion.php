<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\RolEstudio;
use App\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta las rutas de administración del estudio.
 *
 * POR QUÉ EL 403 Y NO EL 404 AQUÍ
 * ------------------------------
 * En el resto de la aplicación un rechazo es 404, para no confirmar que el
 * recurso existe. Pero esta puerta no habla de un recurso: habla de una persona.
 * "Su rol no administra esta instalación" no revela nada sobre fotos de nadie, y
 * un mensaje claro evita que alguien pida permisos que ya no va a tener.
 *
 * SON DOS CAPAS, NO UNA
 * --------------------
 * El middleware es la puerta y la `Policy` es la regla. El middleware corta antes
 * de que la petición llegue al controlador, lo que además evita resolver
 * relaciones que no se van a mostrar. Ver docs/03.
 */
class SoloAdministracion
{
    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = $peticion->user();

        if (! $usuario instanceof Usuario) {
            return response()->json(['mensaje' => 'No hay sesión abierta.'], 401);
        }

        if ($usuario->rol !== RolEstudio::Administracion) {
            return response()->json([
                'mensaje' => 'Esta sección es de administración del estudio.',
            ], 403);
        }

        return $siguiente($peticion);
    }
}