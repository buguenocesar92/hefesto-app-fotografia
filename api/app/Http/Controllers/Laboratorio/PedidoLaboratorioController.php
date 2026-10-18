<?php

declare(strict_types=1);

namespace App\Http\Controllers\Laboratorio;

use App\Enums\EstadoPedidoLaboratorio;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarPedidoLaboratorioRequest;
use App\Models\Laboratorio;
use App\Models\PedidoLaboratorio;
use App\Services\Laboratorio\SeguimientoDePedidos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Impresión por subcontrato.
 *
 * POR QUÉ EL PLAZO SE MUESTRA DOBLE
 * ---------------------------------
 * Porque son dos promesas distintas y confundirlas es como un estudio termina
 * diciéndole a la gente que el laboratorio es culpable de una fecha que el estudio
 * mismo se dio. `fecha_prometida_recepcion` es el compromiso con el cliente;
 * `dias_plazo_laboratorio` es lo que se le pidió al laboratorio.
 *
 * POR QUÉ EL CONTENIDO SE CONGELA AL ENVIAR
 * -----------------------------------------
 * Un pedido `enviado` es un trabajo que otro está haciendo. Cambiar la cantidad
 * después produce una factura que no cuadra con lo entregado. Ver
 * `SeguimientoDePedidos`.
 */
class PedidoLaboratorioController extends Controller
{
    public function __construct(private readonly SeguimientoDePedidos $seguimiento) {}

    public function index(Request $peticion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('viewAny', PedidoLaboratorio::class);

        $consulta = PedidoLaboratorio::query()
            ->with('laboratorio:id,nombre')
            ->orderByDesc('fecha_envio')
            ->orderByDesc('created_at');

        $estado = $peticion->query('estado');

        if (is_string($estado) && $estado !== '' && EstadoPedidoLaboratorio::tryFrom($estado) !== null) {
            $consulta->where('estado', $estado);
        }

        return response()->json([
            'pedidos' => $consulta->limit(200)->get()->map(
                static fn (PedidoLaboratorio $p): array => [
                    'id' => (string) $p->id,
                    'codigo' => (string) $p->codigo,
                    'laboratorio' => $p->laboratorio?->nombre,
                    'sesion_id' => $p->sesion_id,
                    'estado' => $p->estado->value,
                    'estado_etiqueta' => $p->estado->etiqueta(),
                    'fecha_envio' => $p->fecha_envio?->format('d-m-Y'),
                    'fecha_prometida_recepcion' => $p->fecha_prometida_recepcion?->format('d-m-Y'),
                    'dias_plazo_laboratorio' => $p->dias_plazo_laboratorio,
                    'fecha_recepcion_real' => $p->fecha_recepcion_real?->format('d-m-Y'),
                    'costo_total' => (int) $p->costo_total,
                    'cantidad_pedida' => $p->cantidadPedida(),
                    'cantidad_recibida' => $p->cantidadRecibida(),
                    'tiene_faltantes' => $p->estado->tieneFaltantes(),
                    'vencido' => $p->estaVencido(),
                    'plazo_configurado' => $p->fecha_prometida_recepcion !== null,
                ]
            )->all(),

            // La lista de formatos sale de la configuración, y nace vacía a
            // propósito: cada laboratorio ofrece los suyos. Ver
            // config/fotografia.php.
            'formatos' => config('fotografia.impresion.formatos'),
        ]);
    }

    public function show(Request $peticion, PedidoLaboratorio $pedido): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('view', $pedido);

        return response()->json([
            'pedido' => [
                'id' => (string) $pedido->id,
                'codigo' => (string) $pedido->codigo,
                'laboratorio' => $pedido->laboratorio?->nombre,
                'estado' => $pedido->estado->value,
                'estado_etiqueta' => $pedido->estado->etiqueta(),
                'fecha_envio' => $pedido->fecha_envio?->format('d-m-Y'),
                'fecha_prometida_recepcion' => $pedido->fecha_prometida_recepcion?->format('d-m-Y'),
                'dias_plazo_laboratorio' => $pedido->dias_plazo_laboratorio,
                'fecha_recepcion_real' => $pedido->fecha_recepcion_real?->format('d-m-Y'),
                'costo_total' => (int) $pedido->costo_total,
                'observaciones' => $pedido->observaciones,
                'admite_cambio_de_contenido' => $pedido->estado->admiteCambioDeContenido(),
                'items' => $pedido->items->map(static fn ($item): array => [
                    'id' => (string) $item->id,
                    'tipo' => $item->tipo->value,
                    'tipo_etiqueta' => $item->tipo->etiqueta(),
                    'formato' => (string) $item->formato,
                    'cantidad_pedida' => (int) $item->cantidad_pedida,
                    'cantidad_recibida' => (int) $item->cantidad_recibida,
                    'cantidad_faltante' => $item->cantidadFaltante(),
                    'precio_unitario' => (int) $item->precio_unitario,
                    'seleccion_id' => $item->seleccion_id,
                ])->all(),
            ],
        ]);
    }

    public function store(GuardarPedidoLaboratorioRequest $peticion): JsonResponse
    {
        $usuario = $this->usuarioObligatorio($peticion);

        $this->authorize('create', PedidoLaboratorio::class);

        $datos = $peticion->validated();

        $costo = 0;

        foreach ($datos['items'] as $item) {
            $costo += (int) $item['cantidad_pedida'] * (int) $item['precio_unitario'];
        }

        $pedido = PedidoLaboratorio::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'laboratorio_id' => $datos['laboratorio_id'],
            'sesion_id' => $datos['sesion_id'] ?? null,
            'contrato_id' => $datos['contrato_id'] ?? null,
            'codigo' => $datos['codigo'],
            'fecha_prometida_recepcion' => $datos['fecha_prometida_recepcion'] ?? null,
            'dias_plazo_laboratorio' => $datos['dias_plazo_laboratorio'] ?? null,
            'estado' => $datos['estado'],
            'costo_total' => $costo,
            'observaciones' => $datos['observaciones'] ?? null,
        ]);

        foreach ($datos['items'] as $item) {
            $pedido->items()->create([
                'tenant_id' => $usuario->tenant_id,
                'tipo' => $item['tipo'],
                'formato' => $item['formato'],
                'cantidad_pedida' => $item['cantidad_pedida'],
                'cantidad_recibida' => 0,
                'precio_unitario' => $item['precio_unitario'],
                'seleccion_id' => $item['seleccion_id'] ?? null,
            ]);
        }

        return response()->json([
            'mensaje' => 'Pedido abierto.',
            'pedido_id' => (string) $pedido->id,
        ], 201);
    }

    /** Marca el pedido como enviado al laboratorio. */
    public function enviar(Request $peticion, PedidoLaboratorio $pedido): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('enviar', $pedido);

        $fecha = $peticion->input('fecha_envio');

        $this->seguimiento->enviar(
            $pedido,
            is_string($fecha) && $fecha !== '' ? $fecha : today()
        );

        return response()->json([
            'mensaje' => 'Pedido enviado. El contenido queda congelado.',
            'pedido_id' => (string) $pedido->id,
            'estado' => $pedido->refresh()->estado->value,
        ]);
    }

    /**
     * Registra lo que volvió del laboratorio.
     *
     * El estado final lo decide el cálculo, no quien aprieta el botón: si todos los
     * ítems volvieron completos, `recibido`; si quedó algo,
     * `recibido_con_fallos`.
     */
    public function recibir(Request $peticion, PedidoLaboratorio $pedido): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('recibir', $pedido);

        $recibidas = $peticion->input('recibidas');

        if (! is_array($recibidas)) {
            return response()->json([
                'mensaje' => 'Hay que indicar cuántas unidades volvieron de cada ítem.',
                'errores' => ['recibidas' => ['Falta el detalle de lo recibido.']],
            ], 422);
        }

        $observaciones = $peticion->input('observaciones');

        $this->seguimiento->recibir(
            $pedido,
            array_map('intval', $recibidas),
            $peticion->input('fecha_recepcion') ?: today(),
            is_string($observaciones) ? $observaciones : null,
        );

        $pedido->refresh();

        return response()->json([
            'mensaje' => $pedido->estado->tieneFaltantes()
                ? 'Recepción registrada con faltantes. El faltante queda anotado.'
                : 'Recepción registrada completa.',
            'estado' => $pedido->estado->value,
            'cantidad_pedida' => $pedido->cantidadPedida(),
            'cantidad_recibida' => $pedido->cantidadRecibida(),
        ]);
    }

    /** Los laboratorios del estudio. */
    public function laboratorios(Request $peticion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        return response()->json([
            'laboratorios' => Laboratorio::query()
                ->where('activo', true)
                ->orderBy('nombre')
                ->get()
                ->map(static fn (Laboratorio $l): array => [
                    'id' => (string) $l->id,
                    'nombre' => (string) $l->nombre,
                    'contacto' => $l->contacto,
                    'telefono' => $l->telefono,
                ])->all(),

            'estados' => array_map(static fn (EstadoPedidoLaboratorio $e): array => [
                'valor' => $e->value,
                'etiqueta' => $e->etiqueta(),
            ], EstadoPedidoLaboratorio::cases()),

            // El plazo por omisión del laboratorio sale de la configuración y
            // nace en `null`. Ver config/fotografia.php.
            'dias_promesa_laboratorio' => config('fotografia.impresion.dias_promesa_laboratorio_dias'),
            'plazo_configurado' => config('fotografia.impresion.dias_promesa_laboratorio_dias') !== null,
        ]);
    }
}