<?php

declare(strict_types=1);

namespace App\Services\Cobranza;

use App\Enums\EstadoPedidoLaboratorio;
use App\Enums\EstadoSesion;
use App\Models\Contrato;
use App\Models\Sesion;
use Illuminate\Support\Collection;

/**
 * Lee el contrato y se lo pasa a la calculadora.
 *
 * POR QUÉ ESTA CLASE EXISTE
 * -------------------------
 * Porque la cuenta no sale de una tabla. Sale de las líneas, de los abonos y de
 * lo que está pasando en las sesiones y en los pedidos al laboratorio. Solo esta
 * clase lee esas tablas; `CalculadoraDeSaldo` no sabe qué es un modelo.
 *
 * LAS OBSERVACIONES QUE SÓLO ESTA CLASE PUEDE VER
 * -----------------------------------------------
 * La aritmética la hace la calculadora, pero hay dos cosas que se saben leyendo:
 *
 *   - Una sesión `pendiente_seleccion` tiene fotos sin que nadie haya elegido.
 *     No es un error: es un estado normal. Y es la razón por la que su línea de
 *     impresiones todavía no se puede entregar.
 *   - Un pedido a laboratorio vencido es trabajo que el estudio le prometió a
 *     alguien y que no depende de él.
 *
 * Las dos van al desglose como palabras, no como número. Un saldo no se explica
 * solo.
 */
final class ServicioDeSaldo
{
    public function __construct(private readonly CalculadoraDeSaldo $calculadora) {}

    public function para(Contrato $contrato): DesgloseDeSaldo
    {
        $lineas = $contrato->lineas()->orderBy('tipo')->orderBy('concepto')->get();

        $abonos = $contrato->abonos()->orderBy('fecha')->get();

        return $this->calculadora->calcular($this->armar($contrato, $lineas, $abonos));
    }

    /**
     * El mismo cálculo para los contratos que se muestran juntos, en la pantalla
     * de saldos.
     *
     * Existe para no ir a la base una vez por contrato. Con cien contratos
     * abiertos, consultarlos de uno en uno son doscientas idas a la base para
     * pintar una tabla.
     *
     * @param  Collection<int, Contrato>  $contratos
     * @return Collection<int, DesgloseDeSaldo>
     */
    public function paraVarios(Collection $contratos): Collection
    {
        return $contratos->map(fn (Contrato $contrato): DesgloseDeSaldo => $this->para($contrato));
    }

    /**
     * @param  Collection<int, \App\Models\LineaContrato>  $lineas
     * @param  Collection<int, \App\Models\Abono>  $abonos
     */
    private function armar(Contrato $contrato, Collection $lineas, Collection $abonos): CobroAContar
    {
        $observaciones = $this->observacionesDeSesiones($contrato);

        return new CobroAContar(
            contratoId: (string) $contrato->id,
            codigo: (string) $contrato->codigo,
            precioContrato: (int) $contrato->precio_total,
            lineas: $lineas
                ->map(static fn ($linea): LineaAContar => new LineaAContar(
                    concepto: (string) $linea->concepto,
                    cantidadPactada: (int) $linea->cantidad_pactada,
                    cantidadEntregada: (int) $linea->cantidad_entregada,
                    precioUnitario: (int) $linea->precio_unitario,
                    unidad: self::unidadDe($linea),
                    tipo: $linea->tipo instanceof \BackedEnum ? $linea->tipo->value : null,
                    lineaId: (string) $linea->id,
                ))
                ->all(),
            abonos: $abonos
                ->map(static fn ($abono): AbonoContado => new AbonoContado(
                    codigo: (string) $abono->codigo,
                    monto: (int) $abono->monto,
                    fecha: $abono->fecha instanceof \DateTimeInterface
                        ? $abono->fecha->format('Y-m-d')
                        : (string) $abono->fecha,
                    metodo: $abono->metodo instanceof \BackedEnum ? $abono->metodo->value : (string) $abono->metodo,
                    referencia: $abono->referencia,
                    dejaRastro: $abono->dejaRastro(),
                    abonoId: (string) $abono->id,
                ))
                ->all(),
            observaciones: $observaciones,
        );
    }

    /**
     * Lo que está frenando la entrega, dicho en palabras.
     *
     * @return list<string>
     */
    private function observacionesDeSesiones(Contrato $contrato): array
    {
        $observaciones = [];

        $esperando = Sesion::query()
            ->where('contrato_id', $contrato->id)
            ->where('estado', EstadoSesion::PendienteSeleccion->value)
            ->count();

        if ($esperando > 0) {
            $observaciones[] = sprintf(
                'Hay %d sesión(es) pendiente(s) de selección. Hasta que la persona elija no hay nada que imprimir, '
                . 'así que las líneas de impresión no se pueden dar por entregadas.',
                $esperando
            );
        }

        $vencidos = $contrato->pedidosLaboratorio()
            ->whereNotIn('estado', [
                EstadoPedidoLaboratorio::Recibido->value,
                EstadoPedidoLaboratorio::RecibidoConFallos->value,
                EstadoPedidoLaboratorio::Anulado->value,
            ])
            ->whereNotNull('fecha_prometida_recepcion')
            ->whereDate('fecha_prometida_recepcion', '<', today())
            ->count();

        if ($vencidos > 0) {
            $observaciones[] = sprintf(
                'Hay %d pedido(s) a laboratorio cuya fecha prometida al cliente ya pasó. '
                . 'La entrega depende de un tercero.',
                $vencidos
            );
        }

        return $observaciones;
    }

    /** La unidad en la que se cuenta una línea. */
    private static function unidadDe(object $linea): string
    {
        if ($linea->unidad_minutos !== null) {
            return 'minutos';
        }

        return match ($linea->tipo instanceof \BackedEnum ? $linea->tipo->value : '') {
            'impresion' => 'unidades impresas',
            'album' => 'álbumes',
            default => 'unidades',
        };
    }
}