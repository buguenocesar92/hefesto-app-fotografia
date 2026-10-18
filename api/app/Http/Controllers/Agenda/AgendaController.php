<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agenda;

use App\Enums\EstadoSesion;
use App\Enums\TipoSesion;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgendarSesionRequest;
use App\Models\Sesion;
use App\Models\Usuario;
use App\Services\Sesiones\GestorDeEstadoDeSesion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La agenda: las sesiones con fecha prometida.
 *
 * POR QUÉ LA AGENDA ESTÁ SEPARADA DE LAS SESIONES
 * ----------------------------------------------
 * La agenda se lee por fecha y devuelve poco: título, tipo, quién atiende. La
 * pantalla de sesiones muestra el detalle, la selección y la conservación. Una
 * sola pantalla para las dos cosas obliga a traer todo para pintar una agenda, y
 * en un estudio con cien sesiones de tres años eso es traer datos de fotos de
 * familias para pintar un calendario.
 *
 * POR QUÉ EL PLAZO DE ENTREGA SE CALCULA AL AGENDAR
 * -------------------------------------------------
 * Porque la fecha prometida de entrega depende de la fecha de la sesión, y el
 * plazo sale de la configuración. Si la configuración está en `null` —que es lo
 * que pasa en una instalación nueva y es lo correcto hasta que el estudio
 * declare su plazo—, no hay fecha prometida de entrega y la respuesta lo dice
 * con `null`. La pantalla lo muestra como "sin plazo configurado", no como una
 * fecha inventada. Ver docs/06.
 */
class AgendaController extends Controller
{
    public function __construct(private readonly GestorDeEstadoDeSesion $gestor) {}

    /**
     * La agenda de un rango de fechas.
     */
    public function show(Request $peticion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $desde = $peticion->query('desde');
        $hasta = $peticion->query('hasta');

        $consulta = Sesion::query()
            ->with(['cliente:id,nombre,apellido', 'fotografo:id,nombre'])
            ->orderBy('fecha_prometida');

        if (is_string($desde) && $desde !== '') {
            $consulta->whereDate('fecha_prometida', '>=', $desde);
        }

        if (is_string($hasta) && $hasta !== '') {
            $consulta->whereDate('fecha_prometida', '<=', $hasta);
        }

        return response()->json([
            'sesiones' => $consulta->limit(500)->get()->map(static fn (Sesion $s): array => [
                'id' => (string) $s->id,
                'titulo' => (string) $s->titulo,
                'tipo' => $s->tipo->value,
                'tipo_etiqueta' => $s->tipo->etiqueta(),
                'lugar' => $s->lugar,
                'cliente' => $s->cliente?->nombreCompleto(),
                'fotografo' => $s->fotografo?->nombre,
                'fecha_prometida' => $s->fecha_prometida?->format('d-m-Y'),
                'fecha_realizada' => $s->fecha_realizada?->format('d-m-Y'),
                'fecha_prometida_entrega' => $s->fecha_prometida_entrega?->format('d-m-Y'),
                'estado' => $s->estado->value,
                'estado_etiqueta' => $s->estado->etiqueta(),
                'duracion_minutos' => $s->duracion_minutos,
                'puede_incluir_menores' => $s->tipo->puedeIncluirMenores(),
            ])->all(),

            'configuracion' => [
                'duracion_maxima_minutos' => (int) config('fotografia.agenda.duracion_maxima_minutos'),
                'anticipacion_maxima_dias' => (int) config('fotografia.agenda.anticipacion_maxima_dias'),
                'anticipacion_minima_dias' => (int) config('fotografia.agenda.anticipacion_minima_dias'),
                // La pantalla tiene que poder decir que no hay plazo, en vez de
                // mostrar una fecha que el sistema se inventó.
                'dias_promesa_por_omision' => config('fotografia.entrega.dias_promesa_por_omision'),
                'plazo_configurado' => config('fotografia.entrega.dias_promesa_por_omision') !== null,
            ],
        ]);
    }

    /**
     * Los tipos de sesión, para el formulario de agendar.
     *
     * @return JsonResponse
     */
    public function tipos(): JsonResponse
    {
        $this->usuarioObligatorio($peticion = request());

        return response()->json([
            'tipos' => array_map(static fn (TipoSesion $tipo): array => [
                'valor' => $tipo->value,
                'etiqueta' => $tipo->etiqueta(),
                'puede_incluir_menores' => $tipo->puedeIncluirMenores(),
                'fecha_critica' => $tipo->esFechaCritica(),
            ], TipoSesion::cases()),
        ]);
    }

    /**
     * Las personas que pueden atender una sesión.
     *
     * No es el listado de administración de cuentas: es lo mínimo que necesita la
     * pantalla de agenda, y por eso no trae correos ni el permiso de abrir
     * originales.
     */
    public function fotografos(Request $peticion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        return response()->json([
            'fotografos' => Usuario::query()
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre'])
                ->map(static fn (Usuario $u): array => [
                    'id' => (string) $u->id,
                    'nombre' => (string) $u->nombre,
                ])->all(),
        ]);
    }

    public function store(AgendarSesionRequest $peticion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('create', Sesion::class);

        $usuario = $peticion->user();
        $datos = $peticion->validated();

        $diasPromesa = $datos['dias_promesa_entrega']
            ?? config('fotografia.entrega.dias_promesa_por_omision');

        $sesion = Sesion::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'contrato_id' => $datos['contrato_id'] ?? null,
            'cliente_id' => $datos['cliente_id'],
            'fotografo_id' => $datos['fotografo_id'] ?? null,
            'tipo' => $datos['tipo'],
            'titulo' => $datos['titulo'],
            'lugar' => $datos['lugar'] ?? null,
            'fecha_prometida' => $datos['fecha_prometida'],
            'estado' => EstadoSesion::Agendada->value,
            'duracion_minutos' => $datos['duracion_minutos'] ?? null,
            // Con el plazo en `null`, no hay fecha prometida de entrega. Se
            // guarda así y no se deduce. Ver la nota del encabezado.
            'fecha_prometida_entrega' => $diasPromesa === null
                ? null
                : \Illuminate\Support\Carbon::parse($datos['fecha_prometida'])
                    ->addDays((int) $diasPromesa),
        ]);

        return response()->json([
            'mensaje' => 'Sesión agendada.',
            'sesion' => $this->mostrar($sesion),
        ], 201);
    }

    public function show_(Request $peticion, Sesion $sesion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('view', $sesion);

        return response()->json([
            'sesion' => $this->mostrar($sesion->load(['cliente', 'fotografo', 'contrato'])),
        ]);
    }

    /**
     * Mueve la sesión a `realizada`.
     *
     * Es otra ruta y otro método porque no es editar la sesión: es escribir lo que
     * pasó. Por eso pide la fecha en que se tomó.
     */
    public function registrarRealizacion(Request $peticion, Sesion $sesion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('update', $sesion);

        $fecha = $peticion->input('fecha_realizada');

        if (! is_string($fecha) || $fecha === '') {
            return response()->json([
                'mensaje' => 'Hay que decir qué día se tomó la sesión.',
                'errores' => ['fecha_realizada' => ['Falta la fecha en que se tomó la sesión.']],
            ], 422);
        }

        $this->gestor->moverA($sesion, EstadoSesion::Realizada, $fecha);

        return response()->json([
            'mensaje' => 'Sesión marcada como realizada.',
            'sesion' => $this->mostrar($sesion->refresh()),
        ]);
    }

    /**
     * El detalle de la sesión, con lo que la pantalla necesita para no tener que
     * inventar nada.
     *
     * @return array<string, mixed>
     */
    private function mostrar(Sesion $sesion): array
    {
        $gestor = app(GestorDeEstadoDeSesion::class);

        return [
            'id' => (string) $sesion->id,
            'titulo' => (string) $sesion->titulo,
            'tipo' => $sesion->tipo->value,
            'tipo_etiqueta' => $sesion->tipo->etiqueta(),
            'lugar' => $sesion->lugar,
            'cliente_id' => (string) $sesion->cliente_id,
            'cliente' => $sesion->cliente?->nombreCompleto(),
            'contrato_id' => $sesion->contrato_id,
            'fotografo_id' => $sesion->fotografo_id,
            'fecha_prometida' => $sesion->fecha_prometida?->format('d-m-Y'),
            'fecha_realizada' => $sesion->fecha_realizada?->format('d-m-Y'),
            'fecha_prometida_entrega' => $sesion->fecha_prometida_entrega?->format('d-m-Y'),
            'estado' => $sesion->estado->value,
            'estado_etiqueta' => $sesion->estado->etiqueta(),
            'duracion_minutos' => $sesion->duracion_minutos,
            'conservacion_id' => $sesion->conservacion_id,
            'tiene_seleccion_confirmada' => $sesion->tieneSeleccionConfirmada(),

            // Por qué el botón de entregar está deshabilitado, en palabras. La
            // misma comprobación que hace el servidor, mostrada antes.
            'motivo_de_no_poder_entregar' => $gestor->motivoDeNoPoderEntregar($sesion),

            'transiciones_posibles' => array_map(
                static fn (EstadoSesion $estado): string => $estado->value,
                $gestor->posiblesDesde($sesion->estado)
            ),
        ];
    }
}