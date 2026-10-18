<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cobranza;

use App\Enums\MetodoAbono;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarAbonoRequest;
use App\Models\Abono;
use App\Models\Contrato;
use App\Services\Cobranza\ServicioDeSaldo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Caja: saldos y abonos.
 *
 * LA RESPUESTA ES UN DESGLOSE, NO UN NÚMERO
 * -----------------------------------------
 * Quien atiende necesita responder tres cosas distintas: "¿qué prometí?", "¿cuánto
 * entregué y cuánto falta?" y "¿cuánto me deben?". Un total único no contesta
 * ninguna de las tres. Ver `App\Services\Cobranza\DesgloseDeSaldo`.
 *
 * UN ABONO NO ES UNA ENTREGA
 * -------------------------
 * Son dos hechos y viven en dos tablas. Que se hayan entregado veinte fotos no
 * dice que se hayan pagado. Por eso esta pantalla no deduce el saldo de la
 * entrega, y la de entregas no toca el dinero.
 */
class SaldoController extends Controller
{
    public function __construct(private readonly ServicioDeSaldo $servicioDeSaldo) {}

    /**
     * Los saldos de los contratos abiertos, con su desglose.
     */
    public function index(Request $peticion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('viewAny', Abono::class);

        $contratos = Contrato::query()
            ->whereNotIn('estado', ['anulado'])
            ->with('cliente:id,nombre,apellido')
            ->orderBy('fecha_contrato')
            ->get();

        $desgloses = $this->servicioDeSaldo->paraVarios($contratos);

        $filas = $contratos->map(function (Contrato $contrato, int $indice) use ($desgloses): array {
            $desglose = $desgloses[$indice];

            return [
                'contrato_id' => (string) $contrato->id,
                'codigo' => (string) $contrato->codigo,
                'cliente' => $contrato->cliente?->nombreCompleto(),
                'estado' => $contrato->estado->value,
                'estado_etiqueta' => $contrato->estado->etiqueta(),
                'precio_contrato' => $desglose->precioContrato(),
                'total_entregado' => $desglose->totalEntregado(),
                'valor_por_entregar' => $desglose->valorPorEntregar(),
                'cantidad_pendiente' => $desglose->cantidadPendiente(),
                'total_abonado' => $desglose->totalAbonado(),
                'saldo_pendiente' => $desglose->saldoPendiente(),
                'saldo_a_favor' => $desglose->saldoAFavor(),
                'esta_todo_entregado' => $desglose->estaTodoEntregado(),
                'observaciones' => $desglose->observaciones,
            ];
        })->all();

        $totalPorCobrar = array_sum(array_column($filas, 'saldo_pendiente'));
        $totalPorEntregar = array_sum(array_column($filas, 'valor_por_entregar'));

        return response()->json([
            'saldos' => $filas,
            'totales' => [
                'por_cobrar' => $totalPorCobrar,
                'por_entregar' => $totalPorEntregar,
                'entregado' => array_sum(array_column($filas, 'total_entregado')),
                'abonado' => array_sum(array_column($filas, 'total_abonado')),
            ],
        ]);
    }

    /**
     * El desglose de un contrato, línea por línea y abono por abono.
     */
    public function show(Request $peticion, Contrato $contrato): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('view', $contrato);

        return response()->json([
            'desglose' => $this->servicioDeSaldo->para($contrato)->aArray(),
        ]);
    }

    /**
     * Registra un abono.
     */
    public function registrar(RegistrarAbonoRequest $peticion, Contrato $contrato): JsonResponse
    {
        $usuario = $this->usuarioObligatorio($peticion);

        $this->authorize('create', [Abono::class, $contrato]);

        $datos = $peticion->validated();

        $abono = Abono::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'contrato_id' => $contrato->id,
            'registrado_por' => $usuario->id,
            'codigo' => $datos['codigo'],
            'fecha' => $datos['fecha'],
            'monto' => $datos['monto'],
            'metodo' => $datos['metodo'],
            'referencia' => $datos['referencia'] ?? null,
            'notas' => $datos['notas'] ?? null,
        ]);

        return response()->json([
            'mensaje' => 'Abono registrado.',
            'abono' => [
                'id' => (string) $abono->id,
                'codigo' => (string) $abono->codigo,
                'fecha' => $abono->fecha?->format('d-m-Y'),
                'monto' => (int) $abono->monto,
                'metodo' => $abono->metodo->value,
                'metodo_etiqueta' => $abono->metodo->etiqueta(),
                'deja_rastro' => $abono->dejaRastro(),
            ],
            // El desglose se vuelve a calcular para que la pantalla muestre la
            // cifra nueva sin tener que pedirla aparte.
            'desglose' => $this->servicioDeSaldo->para($contrato->refresh())->aArray(),
        ], 201);
    }

    /**
     * Los métodos de pago, para el formulario de abono.
     *
     * Se devuelven desde el enum y no desde la base, para que no haya una lista
     * de medios de pago en dos lugares distintos. Ver `App\Enums\MetodoAbono`.
     */
    public function metodos(): JsonResponse
    {
        return response()->json([
            'metodos' => array_map(static fn (MetodoAbono $metodo): array => [
                'valor' => $metodo->value,
                'etiqueta' => $metodo->etiqueta(),
                'deja_rastro' => $metodo->dejaRastro(),
            ], MetodoAbono::cases()),

            'politica_de_abono' => [
                'porcentaje_abono_inicial' => (int) config('fotografia.abonos.porcentaje_abono_inicial'),
                'exigir_abono_inicial' => (bool) config('fotografia.abonos.exigir_abono_inicial'),
                'permitir_abono_parcial' => (bool) config('fotografia.abonos.permitir_abono_parcial'),
            ],
        ]);
    }
}