<?php

declare(strict_types=1);

namespace App\Services\Cobranza;

/**
 * Calcula el saldo de un contrato Y SU DESGLOSE.
 *
 * POR QUÉ ESTA CLASE NO TOCA LA BASE DE DATOS
 * -------------------------------------------
 * Porque así su prueba es una prueba unitaria de verdad. Una prueba que necesita
 * PostgreSQL no se corre todos los días, y un cálculo de plata que solo se
 * comprueba cuando alguien se acuerda es un cálculo que nadie se cree.
 *
 * La separación es esta: `ServicioDeSaldo` lee los modelos y arma un
 * `CobroAContar`; esta clase solo cuenta. No hay una sola consulta SQL en el
 * archivo, y por eso se puede probar con cinco objetos.
 *
 * LO QUE DEVUELVE
 * ----------------
 * Un `DesgloseDeSaldo`, que son ocho cifras y dos listas. NO un entero. Quien
 * necesite solo el total lo pide con `saldoPendiente()`; quien necesite entender
 * de dónde sale, tiene el desglose.
 *
 * NO HAY NÚNERO MÁGICO
 * --------------------
 * No hay porcentajes, no hay redondeos proporcionales, no hay un "cargo por
 * gestión" oculto. La suma de las líneas es la suma de las líneas, y la
 * diferencia con el precio del contrato se INFORMA en vez de repartirse sola.
 */
final class CalculadoraDeSaldo
{
    public function calcular(CobroAContar $cobro): DesgloseDeSaldo
    {
        $lineas = array_map(
            static fn (LineaAContar $linea): DesgloseDeLinea => new DesgloseDeLinea(
                concepto: $linea->concepto,
                cantidadPactada: $linea->cantidadPactada,
                cantidadEntregada: $linea->cantidadEntregada,
                precioUnitario: $linea->precioUnitario,
                unidad: $linea->unidad,
                tipo: $linea->tipo,
                lineaId: $linea->lineaId,
            ),
            $cobro->lineas
        );

        $abonos = array_map(
            static fn (AbonoContado $abono): DesgloseDeAbono => new DesgloseDeAbono(
                codigo: $abono->codigo,
                monto: $abono->monto,
                fecha: $abono->fecha,
                metodo: $abono->metodo,
                referencia: $abono->referencia,
                dejaRastro: $abono->dejaRastro,
                abonoId: $abono->abonoId,
            ),
            $cobro->abonos
        );

        $observaciones = $cobro->observaciones;

        /*
         * Las observaciones que el propio cálculo puede ver. Las del contrato
         * (una sesión sin selección, un pedido de laboratorio atrasado) las
         * agrega `ServicioDeSaldo`, que es el que sabe leer esas tablas.
         *
         * Las de acá salen de la aritmética: una línea entregada de más es un
         * hecho que hay que escribir, no un cero que se pierde.
         */
        foreach ($lineas as $linea) {
            if ($linea->tieneExceso()) {
                $observaciones[] = sprintf(
                    'La línea «%s» tiene %d unidad(es) entregadas por sobre lo pactado. '
                    . 'Eso no descuenta lo pendiente: es trabajo extra y la decisión es del estudio.',
                    $linea->concepto,
                    $linea->unidadesDeExceso()
                );
            }
        }

        // Un contrato sin líneas no se puede cerrar. Se escribe, porque una
        // pantalla con un total y sin explicación hace que alguien pregunte por
        // teléfono y nadie sepa qué responder.
        if ($lineas === []) {
            $observaciones[] = 'Este contrato no tiene líneas de entrega: no hay nada que desglosar '
                . 'y no se puede marcar como entregado.';
        }

        // El precio del contrato y la suma de las líneas son dos cosas
        // distintas. Cuando no coinciden, se dice.
        $diferencia = $cobro->precioContrato - array_sum(
            array_map(static fn (DesgloseDeLinea $linea): int => $linea->pactado(), $lineas)
        );

        if ($lineas !== [] && $diferencia !== 0) {
            $observaciones[] = $diferencia > 0
                ? sprintf(
                    'El precio del contrato es %d pesos mayor que la suma de sus líneas. La diferencia no se repartió sola.',
                    $diferencia
                )
                : sprintf(
                    'La suma de las líneas es %d pesos mayor que el precio del contrato. La diferencia no se repartió sola.',
                    abs($diferencia)
                );
        }

        sort($abonos, static fn (DesgloseDeAbono $a, DesgloseDeAbono $b): int => $a->fecha <=> $b->fecha);

        return new DesgloseDeSaldo(
            contratoId: $cobro->contratoId,
            codigo: $cobro->codigo,
            precioContrato: $cobro->precioContrato,
            lineas: $lineas,
            abonos: $abonos,
            observaciones: array_values(array_unique($observaciones)),
        );
    }
}