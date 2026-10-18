<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/**
 * Base de los controladores de Fotografía.
 *
 * POR QUÉ ESTA CLASE EXISTE
 * -------------------------
 * Para que los controladores no repitan la comprobación de sesión en cada
 * método. La regla de que un rechazo de permiso responde 404 en vez de 403 está
 * en `bootstrap/app.php` y aplica a todos, y esta clase solo deja a mano lo que
 * todos necesitan: quién es la persona de la petición.
 *
 * LO QUE NO HACE
 * --------------
 * No autoriza nada. El permiso de verdad lo da cada `Policy`, y un controlador
 * que se autorizara a sí mismo sería un agujero del tamaño de la aplicación.
 * Ver docs/03.
 */
abstract class Controller
{
    // El método `authorize()` que usan los controladores. Cuando la `Policy`
    // rechaza, lanza `AuthorizationException` y `bootstrap/app.php` la traduce a
    // 404. Ver docs/03.
    use AuthorizesRequests;
    /**
     * La persona de la petición, o `null` si no hay sesión.
     */
    protected function usuarioDe(Request $peticion): ?Usuario
    {
        $usuario = $peticion->user();

        return $usuario instanceof Usuario ? $usuario : null;
    }

    /**
     * La persona de la petición, o un error 401.
     */
    protected function usuarioObligatorio(Request $peticion): Usuario
    {
        $usuario = $this->usuarioDe($peticion);

        if ($usuario === null) {
            abort(401, 'No hay sesión abierta.');
        }

        return $usuario;
    }
}