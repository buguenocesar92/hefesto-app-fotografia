<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contratos;

use App\Enums\EstadoContrato;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarContratoRequest;
use App\Http\Requests\RegistrarEntregaRequest;
use App\Models\Contrato;
use App\Models\LineaContrato;
use App\Services\Cobranza\ServicioDeSaldo;
use App\Services\Contratos\RegistradorDeEntrega;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contratos con paquete: lo que el estudio vendió y todavía no entregó.
 *
 * POR QUÉ CADA ENTREGA ES SU PROPIA RUTA
 * -------------------------------------
 * Registrar una entrega no es actualizar el contrato: es un hecho nuevo, con su
 * fecha y su efecto sobre el saldo. Por eso es `POST .../entregas` y no un
 * `PATCH`. Si fuera un parche, una pantalla que guardara dos veces por error
 * sumaría dos veces.
 *
 * Y por qué NO cierra el contrato: ver `RegistradorDeEntrega`.
 */
class ContratoController extends Controller
{
    public function __construct(
        private readonly ServicioDeSaldo $servicioDeSaldo,
        private readonly RegistradorDeEntrega $registradorDeEntrega,
    ) {}

    public function index(Request $peticion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('viewAny', Contrato::class);

        $consulta = Contrato::query()
            ->with('cliente:id,nombre,apellido')
            ->orderByDesc('fecha_contrato');

        $estado = $peticion->query('estado');

        if (is_string($estado) && $estado !== '' && in_array($estado, ['borrador', 'activo', 'cerrado', 'anulado'], true)) {
            $consulta->where('estado', $estado);
        }

        return response()->json([
            'contratos' => $consulta->limit(200)->get()->map(
                fn (Contrato $c): array => $this->mostrar($c, conDesglose: false)
            )->all(),
        ]);
    }

    public function show(Request $peticion, Contrato $contrato): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('view', $contrato);

        return response()->json([
            'contrato' => $this->mostrar($contrato, conDesglose: true),
        ]);
    }

    public function store(GuardarContratoRequest $peticion): JsonResponse
    {
        $usuario = $this->usuarioObligatorio($peticion);

        $this->authorize('create', Contrato::class);

        $datos = $peticion->validated();

        $contrato = Contrato::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'cliente_id' => $datos['cliente_id'],
            'paquete_id' => $datos['paquete_id'] ?? null,
            'codigo' => $datos['codigo'],
            'fecha_contrato' => $datos['fecha_contrato'],
            'precio_total' => $datos['precio_total'],
            'estado' => $datos['estado'],
            'dias_promesa_entrega' => $datos['dias_promesa_entrega'] ?? null,
            'notas' => $datos['notas'] ?? null,
        ]);

        foreach ($datos['lineas'] as $linea) {
            $contrato->lineas()->create([
                'tenant_id' => $usuario->tenant_id,
                'tipo' => $linea['tipo'],
                'concepto' => $linea['concepto'],
                'unidad_minutos' => $linea['unidad_minutos'] ?? null,
                'cantidad_pactada' => $linea['cantidad_pactada'],
                'cantidad_entregada' => 0,
                'precio_unitario' => $linea['precio_unitario'],
            ]);
        }

        return response()->json([
            'mensaje' => 'Contrato abierto.',
            'contrato' => $this->mostrar($contrato->load(['lineas', 'cliente']), conDesglose: true),
        ], 201);
    }

    /**
     * Anular un contrato. Nunca se borra.
     *
     * Es una ruta propia y no un `DELETE` porque el verbo no es el correcto: no se
     * está borrando nada, se está declarando que ese acuerdo no va a más. Un
     * estudio que borra su historial de fechas prometidas no puede responder por
     * nadie. Ver docs/02.
     */
    public function anular(Request $peticion, Contrato $contrato): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('anular', $contrato);

        $motivo = $peticion->input('motivo');

        if (! is_string($motivo) || trim($motivo) === '') {
            return response()->json([
                'mensaje' => 'Anular un contrato exige escribir por qué. Un contrato sin motivo de anulación '
                    . 'no se puede defender después.',
            ], 422);
        }

        $contrato->estado = EstadoContrato::Anulado;
        $contrato->fecha_cierre = now();
        $contrato->notas = trim($contrato->notas . "\nAnulado: " . trim($motivo));
        $contrato->save();

        return response()->json([
            'mensaje' => 'Contrato anulado.',
            'contrato' => $this->mostrar($contrato, conDesglose: true),
        ]);
    }

    /**
     * Registrar una entrega parcial.
     *
     * NO cierra el contrato. Eso lo decide `RegistradorDeEntrega`, mirando todas
     * las líneas, y no quien aprieta el botón.
     */
    public function registrarEntrega(RegistrarEntregaRequest $peticion, Contrato $contrato): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('registrarEntrega', $contrato);

        $linea = $contrato->lineas()
            ->whereKey($peticion->validated('linea_id'))
            ->first();

        if ($linea === null) {
            // 404, no 422: esa línea no existe en ESTE contrato. Ver docs/03.
            return response()->json(['mensaje' => 'No encontrado.'], 404);
        }

        $this->registradorDeEntrega->registrar(
            $contrato,
            $linea,
            (int) $peticion->validated('cantidad')
        );

        $contrato->load(['lineas', 'cliente']);

        return response()->json([
            'mensaje' => $contrato->estado === EstadoContrato::Cerrado
                ? 'Entrega registrada. Con esto el contrato quedó completo y se cerró.'
                : 'Entrega registrada. El contrato sigue abierto.',
            'contrato' => $this->mostrar($contrato, conDesglose: true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mostrar(Contrato $contrato, bool $conDesglose): array
    {
        $contrato->loadMissing(['cliente', 'lineas']);

        $datos = [
            'id' => (string) $contrato->id,
            'codigo' => (string) $contrato->codigo,
            'cliente_id' => (string) $contrato->cliente_id,
            'cliente' => $contrato->cliente?->nombreCompleto(),
            'paquete_id' => $contrato->paquete_id,
            'fecha_contrato' => $contrato->fecha_contrato?->format('d-m-Y'),
            'precio_total' => (int) $contrato->precio_total,
            'estado' => $contrato->estado->value,
            'estado_etiqueta' => $contrato->estado->etiqueta(),
            'dias_promesa_entrega' => $contrato->dias_promesa_entrega,
            'plazo_configurado' => $contrato->dias_promesa_entrega !== null,
            'fecha_cierre' => $contrato->fecha_cierre?->format('d-m-Y'),

            'lineas' => $contrato->lineas->map(static fn (LineaContrato $l): array => [
                'id' => (string) $l->id,
                'tipo' => $l->tipo->value,
                'tipo_etiqueta' => $l->tipo->etiqueta(),
                'concepto' => (string) $l->concepto,
                'unidad_minutos' => $l->unidad_minutos,
                'cantidad_pactada' => (int) $l->cantidad_pactada,
                'cantidad_entregada' => (int) $l->cantidad_entregada,
                'cantidad_pendiente' => $l->cantidadPendiente(),
                'precio_unitario' => (int) $l->precio_unitario,
                'valor_pactado' => $l->valorPactado(),
                'valor_pendiente' => $l->valorPendiente(),
                'completa' => $l->estaCompleta(),
            ])->all(),
        ];

        // El desglose es caro de calcular: solo se trae cuando la pantalla lo
        // pide. El listado de contratos muestra líneas, no saldos.
        $datos['desglose'] = $conDesglose
            ? $this->servicioDeSaldo->para($contrato)->aArray()
            : null;

        return $datos;
    }
}