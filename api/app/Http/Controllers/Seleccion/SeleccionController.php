<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seleccion;

use App\Enums\EstadoSesion;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarSeleccionRequest;
use App\Models\Sesion;
use App\Services\Seleccion\RegistradorDeSeleccion;
use App\Services\Sesiones\GestorDeEstadoDeSesion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La selección de fotos: qué eligió la persona.
 *
 * POR QUÉ ESTO ES UNA PANTALLA PROPIA Y NO UN DETALLE DE LA SESIÓN
 * ---------------------------------------------------------------
 * Porque es la pantalla donde el estudio espera. La persona abre la galería, mira
 * y elige; hasta que elige, no hay nada que imprimir y el trabajo está parado
 * esperando una decisión de otro. Eso merece su propia pantalla y su propio
 * estado, no una casilla dentro de la ficha de la sesión.
 *
 * `pendiente_seleccion` NO ES UN ERROR
 * ------------------------------------
 * Es el estado NORMAL. Esta pantalla no muestra un error: muestra cuántas hay y
 * de cuándo. Tratarlo como falla haría que el equipo aprendiera a ignorar la
 * pantalla. Ver docs/03.
 *
 * LO QUE HACE ESTE CONTROLADOR Y NO LA PANTALLA
 * ---------------------------------------------
 * La comprobación de que las fotos pertenezcan a la sesión, y la de que la
 * selección no se confirme vacía. Las dos son del servidor, porque una pantalla se
 * puede modificar.
 */
class SeleccionController extends Controller
{
    public function __construct(
        private readonly RegistradorDeSeleccion $registrador,
        private readonly GestorDeEstadoDeSesion $gestor,
    ) {}

    /**
     * La galería de la sesión, con la marca de cuáles están elegidas.
     */
    public function index(Request $peticion, Sesion $sesion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('seleccionar', $sesion);

        $seleccion = $sesion->seleccion;
        $conteo = $this->registrador->conteo($sesion);

        return response()->json([
            'sesion' => [
                'id' => (string) $sesion->id,
                'titulo' => (string) $sesion->titulo,
                'estado' => $sesion->estado->value,
                'estado_etiqueta' => $sesion->estado->etiqueta(),
                'fecha_prometida' => $sesion->fecha_prometida?->format('d-m-Y'),
                'fecha_prometida_entrega' => $sesion->fecha_prometida_entrega?->format('d-m-Y'),
                'cliente' => $sesion->cliente?->nombreCompleto(),
            ],

            'fotos' => $this->registrador->galeria($sesion)->map(static fn ($foto): array => [
                'id' => (string) $foto->id,
                'nombre_archivo' => (string) $foto->nombre_archivo,
                'extension' => $foto->extension,
                'bytes' => $foto->bytes,
                'elegida' => (bool) $foto->elegida,
            ])->all(),

            'conteo' => $conteo,

            'seleccion' => $seleccion === null ? null : [
                'id' => (string) $seleccion->id,
                'estado' => $seleccion->estado->value,
                'estado_etiqueta' => $seleccion->estado->etiqueta(),
                'cantidad_seleccionada' => (int) $seleccion->cantidad_seleccionada,
                'fecha_confirmacion' => $seleccion->fecha_confirmacion?->toIso8601String(),
                'confirmada' => $seleccion->estaConfirmada(),
            ],

            // Por qué el botón de entregar está deshabilitado, en palabras.
            'motivo_de_no_poder_entregar' => $this->gestor->motivoDeNoPoderEntregar($sesion),

            'exceso' => [
                'tope' => config('fotografia.seleccion.exceso_seleccion_sin_pago'),
                'configurado' => config('fotografia.seleccion.exceso_seleccion_sin_pago') !== null,
            ],
        ]);
    }

    /**
     * Guarda lo elegido.
     *
     * Con `confirmar` en `false`, la sesión queda en `realizada`: se tomó y
     * nadie ha elegido todavía. Con `confirmar` en `true`, queda en
     * `pendiente_seleccion` y recién ahí se puede marcar como entregada.
     */
    public function store(RegistrarSeleccionRequest $peticion, Sesion $sesion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('seleccionar', $sesion);

        $confirmar = (bool) $peticion->validated('confirmar', false);

        $seleccion = $this->registrador->registrar(
            $sesion,
            (string) $sesion->cliente_id,
            (array) ($peticion->validated('fotos') ?? []),
            $confirmar,
        );

        return response()->json([
            'mensaje' => $confirmar
                ? 'Selección confirmada. La sesión queda en pendiente de entrega.'
                : 'Selección guardada. Falta que la persona la confirme.',
            'seleccion' => [
                'id' => (string) $seleccion->id,
                'estado' => $seleccion->estado->value,
                'estado_etiqueta' => $seleccion->estado->etiqueta(),
                'cantidad_seleccionada' => (int) $seleccion->cantidad_seleccionada,
                'confirmada' => $seleccion->estaConfirmada(),
            ],
            'sesion_estado' => $sesion->refresh()->estado->value,
            'puede_entregarse' => $sesion->tieneSeleccionConfirmada(),
        ]);
    }

    /**
     * Marca la sesión como entregada.
     *
     * El permiso no basta: la selección tiene que estar confirmada. Esa
     * comprobación la hace `GestorDeEstadoDeSesion`, y falla con un 422 que dice
     * por qué. Ver docs/03.
     */
    public function entregar(Request $peticion, Sesion $sesion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('entregar', $sesion);

        $this->gestor->moverA($sesion, EstadoSesion::Entregada);

        return response()->json([
            'mensaje' => 'Sesión marcada como entregada.',
            'sesion' => [
                'id' => (string) $sesion->id,
                'estado' => $sesion->refresh()->estado->value,
                'estado_etiqueta' => $sesion->estado->etiqueta(),
            ],
        ]);
    }
}