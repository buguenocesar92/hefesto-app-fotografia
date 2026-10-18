<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Enums\EstadoPedidoLaboratorio;
use App\Enums\EstadoSesion;
use App\Enums\ResultadoVerificacion;
use App\Http\Controllers\Controller;
use App\Models\Sesion;
use App\Services\Conservacion\VerificadorDeConservacion;
use App\Services\Cobranza\ServicioDeSaldo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo primero que se ve al entrar.
 *
 * QUÉ TIENE QUE CONTESTAR ESTA PANTALLA
 * ------------------------------------
 * Las tres preguntas con las que se abre un estudio todas las mañanas:
 *
 *   1. ¿Qué sesiones están esperando que la gente elija?
 *   2. ¿Qué encargos al laboratorio se atrasaron?
 *   3. ¿De qué medios con originales no tengo noticias?
 *
 * Y el dinero: cuánto se le debe. El saldo es un desglose, no un número, y el
 * panel muestra el total porque es lo que se mira; el desglose está en
 * `App\Services\Cobranza\DesgloseDeSaldo`.
 *
 * LO QUE NO HACE
 * --------------
 * No dispara alertas ni envía avisos: esta aplicación no tiene salida de correo
 * configurada. Muestra el hecho y deja que la persona decida. Ver docs/08.
 */
class PanelController extends Controller
{
    public function __invoke(
        Request $peticion,
        ServicioDeSaldo $servicioDeSaldo,
        VerificadorDeConservacion $verificador
    ): JsonResponse {
        $this->usuarioObligatorio($peticion);

        $estudioId = (string) $peticion->user()->tenant_id;

        $pendientesDeSeleccion = Sesion::query()
            ->where('estado', EstadoSesion::PendienteSeleccion->value)
            ->orderBy('fecha_prometida_entrega')
            ->limit(20)
            ->get(['id', 'titulo', 'tipo', 'fecha_prometida', 'fecha_prometida_entrega', 'contrato_id']);

        $atrasadas = Sesion::query()
            ->where('estado', 'pendiente_seleccion')
            ->whereNotNull('fecha_prometida_entrega')
            ->whereDate('fecha_prometida_entrega', '<', today())
            ->count();

        $pedidosVencidos = \App\Models\PedidoLaboratorio::query()
            ->whereNotIn('estado', [
                EstadoPedidoLaboratorio::Recibido->value,
                EstadoPedidoLaboratorio::RecibidoConFallos->value,
                EstadoPedidoLaboratorio::Anulado->value,
            ])
            ->whereNotNull('fecha_prometida_recepcion')
            ->whereDate('fecha_prometida_recepcion', '<', today())
            ->with('laboratorio')
            ->orderBy('fecha_prometida_recepcion')
            ->limit(20)
            ->get();

        $conservacion = $verificador->informe($estudioId);

        $contratos = \App\Models\Contrato::query()
            ->whereNotIn('estado', ['cerrado', 'anulado'])
            ->orderBy('fecha_contrato')
            ->get();

        $saldos = $servicioDeSaldo->paraVarios($contratos);

        $totalPorCobrar = array_sum($saldos
            ->map(static fn ($desglose): int => $desglose->saldoPendiente())
            ->all());

        return response()->json([
            'pendientes_de_seleccion' => $pendientesDeSeleccion->map(static fn (Sesion $s): array => [
                'id' => (string) $s->id,
                'titulo' => (string) $s->titulo,
                'tipo' => $s->tipo->etiqueta(),
                'fecha_prometida' => $s->fecha_prometida?->format('d-m-Y'),
                'fecha_prometida_entrega' => $s->fecha_prometida_entrega?->format('d-m-Y'),
                'atrasada' => $s->fecha_prometida_entrega !== null
                    && $s->fecha_prometida_entrega->lessThan(today()),
            ])->all(),

            'sesiones_atrasadas' => $atrasadas,

            'pedidos_laboratorio_vencidos' => $pedidosVencidos->map(static fn ($p): array => [
                'id' => (string) $p->id,
                'codigo' => (string) $p->codigo,
                'laboratorio' => $p->laboratorio?->nombre,
                'fecha_prometida_recepcion' => $p->fecha_prometida_recepcion?->format('d-m-Y'),
                'estado' => $p->estado->etiqueta(),
            ])->all(),

            'conservacion' => $conservacion,

            'sesiones_por_estado' => [
                'agendada' => Sesion::query()->where('estado', EstadoSesion::Agendada->value)->count(),
                'realizada' => Sesion::query()->where('estado', EstadoSesion::Realizada->value)->count(),
                'pendiente_seleccion' => $pendientesDeSeleccion->count(),
                'entregada' => Sesion::query()->where('estado', EstadoSesion::Entregada->value)->count(),
            ],

            'caja' => [
                'contratos_abiertos' => $contratos->count(),
                'total_por_cobrar' => $totalPorCobrar,
                // El plazo de verificación no está configurado, y la pantalla
                // tiene que poder decirlo. Ver config/fotografia.php.
                'plazo_de_verificacion_configurado' =>
                    config('fotografia.conservacion.dias_max_sin_verificar') !== null,
                'resultado_de_verificacion' => ResultadoVerificacion::Correcta->etiqueta(),
            ],
        ]);
    }
}