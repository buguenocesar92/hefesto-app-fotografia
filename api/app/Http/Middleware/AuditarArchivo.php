<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AccionAccesoArchivo;
use App\Enums\EstadoConservacion;
use App\Models\Sesion;
use App\Services\Tenancy\AuditorDeAcceso;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anota cada intento de abrir un original de una sesión.
 *
 * VA ANTES DEL CONTROLADOR, A PROPÓSITO
 * -------------------------------------
 * Si anotara después del permiso, un intento denegado no dejaría rastro, y el
 * intento denegado es justo el registro que hay que poder revisar. Por eso el
 * middleware anota y después corta.
 *
 * LO QUE ANOTA
 * ------------
 * Permiso o denegado, con la acción, la sesión y la dirección desde la que vino.
 * La escritura va por la conexión de auditoría, que sobrevive a la reversa de la
 * petición. Ver App\Services\Tenancy\AuditorDeAcceso.
 *
 * EL DENEGADO RESPONDE 404
 * -----------------------
 * Un 403 confirmaría que esa sesión existe. En una ficha de fotos de una familia,
 * eso ya es información. Ver docs/03.
 */
class AuditarArchivo
{
    public function __construct(private readonly AuditorDeAcceso $auditor) {}

    public function handle(Request $peticion, Closure $siguiente, string $accion = 'consulta'): Response
    {
        $sesion = $this->resolverSesion($peticion);

        $usuario = $peticion->user();

        if ($sesion === null || $usuario === null) {
            // Sin sesión no hay nada que auditar: el `auth` de la ruta va antes.
            return $siguiente($peticion);
        }

        $accionTipada = AccionAccesoArchivo::tryFrom($accion) ?? AccionAccesoArchivo::Consulta;

        // El permiso de verdad lo da la Policy del controlador. Acá solo se
        // comprueba lo que el middleware puede saber sin tocar la fila: que la
        // cuenta tenga el permiso declarado y que el medio esté vivo.
        $motivo = $this->motivoDeDenegar($usuario, $sesion);

        $this->auditor->anotar(
            estudioId: $usuario->tenant_id !== null ? (string) $usuario->tenant_id : null,
            usuarioId: (string) $usuario->id,
            accion: $accionTipada,
            permitido: $motivo === null,
            sesionId: (string) $sesion->id,
            conservacionId: $sesion->conservacion_id,
            ip: $peticion->ip(),
            detalle: $motivo === null ? [] : ['motivo' => $motivo],
        );

        if ($motivo !== null) {
            // 404, no 403: un 403 confirmaría que la sesión existe.
            return response()->json(['mensaje' => 'No encontrado.'], 404);
        }

        return $siguiente($peticion);
    }

    private function resolverSesion(Request $peticion): ?Sesion
    {
        $id = $peticion->route('sesion');

        if ($id === null) {
            return null;
        }

        $sesion = Sesion::query()->find($id);

        if (! $sesion instanceof Sesion) {
            return null;
        }

        return $sesion;
    }

    /**
     * El motivo del rechazo, o `null` si no hay ninguno.
     *
     * Un medio `desconectada` NO es motivo suficiente para negarse: un disco que
     * se enchufó puede perfectamente tener las fotos. Por eso acá solo se
     * cortan los dos casos que no dependen de que alguien vaya a mirar: la cuenta
     * sin permiso y el medio destruido.
     */
    private function motivoDeDenegar(object $usuario, Sesion $sesion): ?string
    {
        if (property_exists($usuario, 'puede_ver_archivos') && $usuario->puede_ver_archivos !== true) {
            return 'La cuenta no tiene permiso para abrir originales.';
        }

        if ($sesion->conservacion_id === null) {
            return null;
        }

        $conservacion = $sesion->conservacion;

        if ($conservacion === null) {
            return null;
        }

        if ($conservacion->estado === EstadoConservacion::Destruida) {
            return 'La conservación de estos originales figura destruida.';
        }

        return null;
    }
}