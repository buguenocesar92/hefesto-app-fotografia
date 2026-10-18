<?php

declare(strict_types=1);

namespace App\Services\Cobranza;

/**
 * El desglose del saldo de un contrato. NO es un número.
 *
 * LAS TRES CUESTIONES QUE RESPONDE
 * -------------------------------
 * Un estudio tiene que poder responder, en cualquier momento, tres cosas que
 * son distintas y que un total único no puede responder a la vez:
 *
 *   1. ¿Qué prometí?              -> `precioContrato()`
 *   2. ¿Cuánto entregué y cuánto
 *      falta?                    -> `totalEntregado()` y `valorPorEntregar()`
 *   3. ¿Cuánto me deben?          -> `saldoPendiente()`
 *
 * Con una sola cifra no se puede contestar ninguna de las tres, y equivocarse en
 * la segunda es como se termina imprimiendo fotos que nadie eligió.
 *
 * LAS DOS CIFRAS DEL SALDO
 * ------------------------
 * `saldoPendiente()` es lo que el cliente debe AHORA. `saldoAFavor()` es lo que
 * se pagó de más. No se usa un solo número con signo: cuando alguien abona de
 * más hay que verlo escrito, no deducido.
 *
 * NINGÚN NÚMERO ES NEGATIVO
 * -------------------------
 * Las dos cifras son positivas y se calculan con `max(0, ...)`. Un saldo negativo
 * tiene un signo, y un signo se presta a ser mal leído de reojo en la pantalla
 * de caja.
 */
final class DesgloseDeSaldo
{
    /**
     * @param  list<DesgloseDeLinea>  $lineas
     * @param  list<DesgloseDeAbono>  $abonos
     * @param  list<string>  $observaciones
     */
    public function __construct(
        public readonly string $contratoId,
        public readonly string $codigo,
        public readonly int $precioContrato,
        public readonly array $lineas = [],
        public readonly array $abonos = [],
        public readonly array $observaciones = [],
    ) {}

    /** Lo que firmó la persona. */
    public function precioContrato(): int
    {
        return $this->precioContrato;
    }

    /** La suma de lo que prometen las líneas. Puede no ser igual al contrato. */
    public function totalDeLineas(): int
    {
        return array_sum(array_map(
            static fn (DesgloseDeLinea $linea): int => $linea->pactado(),
            $this->lineas
        ));
    }

    /** Lo entregado, en pesos, sumando todas las líneas. */
    public function totalEntregado(): int
    {
        return array_sum(array_map(
            static fn (DesgloseDeLinea $linea): int => $linea->entregado(),
            $this->lineas
        ));
    }

    /** Lo prometido que todavía no se entregó, en pesos. */
    public function valorPorEntregar(): int
    {
        return array_sum(array_map(
            static fn (DesgloseDeLinea $linea): int => $linea->valorPendiente(),
            $this->lineas
        ));
    }

    /** Cuántas unidades faltan, sumando todas las líneas. */
    public function cantidadPendiente(): int
    {
        return array_sum(array_map(
            static fn (DesgloseDeLinea $linea): int => $linea->cantidadPendiente(),
            $this->lineas
        ));
    }

    /** El dinero que ha entrado, sumando todos los abonos. */
    public function totalAbonado(): int
    {
        return array_sum(array_map(
            static fn (DesgloseDeAbono $abono): int => $abono->monto,
            $this->abonos
        ));
    }

    /**
     * Lo que el cliente debe ahora.
     *
     * Se calcula sobre el PRECIO DEL CONTRATO, no sobre lo entregado. La razón
     * es el orden en que funciona un estudio: el abono inicial se cobra al
     * firmar, cuando todavía no se ha tomado ninguna foto, y el resto al
     * entregar. Si el saldo se calculara sobre lo entregado, en el día de la
     * firma el sistema diría que no se debe nada y el abono inicial sería un
     * adelanto que nadie sabe que es una deuda.
     */
    public function saldoPendiente(): int
    {
        return max(0, $this->precioContrato() - $this->totalAbonado());
    }

    /** Lo que se pagó de más. */
    public function saldoAFavor(): int
    {
        return max(0, $this->totalAbonado() - $this->precioContrato());
    }

    /**
     * ¿Se entregó todo lo que se prometió?
     *
     * Un contrato con líneas y ninguna cantidad pendiente está para cerrarse.
     * Uno con líneas en `borrador` no: una línea que promete cero unidades está
     * "completa" por la aritmética, y eso no significa que el trabajo esté hecho.
     */
    public function estaTodoEntregado(): bool
    {
        if ($this->lineas === []) {
            return false;
        }

        foreach ($this->lineas as $linea) {
            if (! $linea->estaCompleta()) {
                return false;
            }
        }

        return true;
    }

    /** ¿Quedó plata por cobrar después de entregarlo todo? */
    public function tieneSaldo(): bool
    {
        return $this->saldoPendiente() > 0;
    }

    /** Las líneas que todavía deben algo. */
    public function lineasPendientes(): array
    {
        return array_values(array_filter(
            $this->lineas,
            static fn (DesgloseDeLinea $linea): bool => ! $linea->estaCompleta()
        ));
    }

    /**
     * La forma en que va a la pantalla y a la respuesta de la API.
     *
     * El nombre del arreglo es `desglose` y no `saldo`, a propósito: lo que
     * viaja no es una cifra.
     *
     * @return array<string, mixed>
     */
    public function aArray(): array
    {
        return [
            'contrato_id' => $this->contratoId,
            'codigo' => $this->codigo,
            'precio_contrato' => $this->precioContrato(),
            'total_de_lineas' => $this->totalDeLineas(),
            'total_entregado' => $this->totalEntregado(),
            'valor_por_entregar' => $this->valorPorEntregar(),
            'cantidad_pendiente' => $this->cantidadPendiente(),
            'total_abonado' => $this->totalAbonado(),
            'saldo_pendiente' => $this->saldoPendiente(),
            'saldo_a_favor' => $this->saldoAFavor(),
            'esta_todo_entregado' => $this->estaTodoEntregado(),
            'tiene_saldo' => $this->tieneSaldo(),
            'lineas' => array_map(
                static fn (DesgloseDeLinea $linea): array => $linea->aArray(),
                $this->lineas
            ),
            'abonos' => array_map(
                static fn (DesgloseDeAbono $abono): array => [
                    'abono_id' => $abono->abonoId,
                    'codigo' => $abono->codigo,
                    'fecha' => $abono->fecha,
                    'monto' => $abono->monto,
                    'metodo' => $abono->metodo,
                    'referencia' => $abono->referencia,
                    'deja_rastro' => $abono->dejaRastro,
                ],
                $this->abonos
            ),
            'observaciones' => $this->observaciones,
        ];
    }
}