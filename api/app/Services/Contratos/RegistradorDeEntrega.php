<?php

declare(strict_types=1);

namespace App\Services\Contratos;

use App\Enums\EstadoContrato;
use App\Enums\TipoLineaContrato;
use App\Models\Contrato;
use App\Models\LineaContrato;
use DomainException;

/**
 * Registrar una entrega, SIN cerrar el contrato.
 *
 * LA REGLA QUE MÁS CUESTA APRENDER
 * --------------------------------
 * Entregar parte no es cerrar. Entregar veinte de cien fotos no termina el
 * trabajo: lo avanza. Por eso este servicio sube `cantidad_entregada` y deja el
 * contrato en `activo`, y solo lo pasa a `cerrado` cuando TODAS las líneas
 * quedaron completas.
 *
 * Una aplicación que cerrara el contrato con la primera entrega entregaría los
 * Hundred casualmente y después no sabría qué faltaba. Ver docs/02.
 *
 * LO QUE ESTA CLASE RECHAZA
 * -------------------------
 *  - Entregar más de lo pactado, sin que nadie lo haya decidido. Una persona
 *    puede elegir más fotos que las del paquete; eso es trabajo extra y lo
 *    autoriza una persona, no un POST.
 *  - Entregar en un contrato que no está activo. Un contrato en `borrador` no
 *    promete nada todavía.
 *  - Entregar una línea que depende de una selección que no está confirmada. Es
 *    la misma regla de las sesiones, aplicada a la línea: no se imprime lo que
 *    nadie eligió.
 */
class RegistradorDeEntrega
{
    /**
     * Registra una entrega y devuelve el contrato con el estado recalculado.
     *
     * @param  int  $cantidad  cuántas unidades se entregan AHORA, no el total
     */
    public function registrar(Contrato $contrato, LineaContrato $linea, int $cantidad): Contrato
    {
        if (! $contrato->estado->admiteTrabajo()) {
            throw new DomainException(sprintf(
                'No se puede registrar una entrega en un contrato %s.',
                $contrato->estado->etiqueta()
            ));
        }

        if ($cantidad <= 0) {
            throw new DomainException('La cantidad entregada tiene que ser mayor que cero.');
        }

        if ($this->tipoEsDeImpresion($linea) && ! $this->tieneSeleccionConfirmada($contrato)) {
            throw new DomainException(
                'No se puede dar por entregada una línea de impresión o de álbum sin una selección confirmada. '
                . 'Imprimir es un encargo a un laboratorio externo, se paga por adelantado y no se devuelve.'
            );
        }

        $nuevoTotal = $linea->cantidad_entregada + $cantidad;

        if ($nuevoTotal > $linea->cantidad_pactada) {
            throw new DomainException(sprintf(
                'La línea «%s» promete %d y ya lleva %d entregados. Entregar %d más superaría lo pactado: '
                . 'eso es trabajo extra y lo tiene que autorizar una persona.',
                $linea->concepto,
                $linea->cantidad_pactada,
                $linea->cantidad_entregada,
                $cantidad
            ));
        }

        $linea->cantidad_entregada = $nuevoTotal;
        $linea->save();

        $this->recalcularEstado($contrato);

        return $contrato;
    }

    /**
     * Cierra el contrato si ya no queda nada por entregar.
     *
     * Y NO lo hace al revés. Un contrato en `cerrado` al que le falte una línea no
     * existe en esta aplicación: el estado se deriva del trabajo, no al revés.
     */
    public function recalcularEstado(Contrato $contrato): Contrato
    {
        $contrato->load('lineas');

        $lineas = $contrato->lineas;

        $todoEntregado = $lineas->isNotEmpty()
            && $lineas->every(static fn (LineaContrato $linea): bool => $linea->estaCompleta());

        if ($todoEntregado && $contrato->estado === EstadoContrato::Activo) {
            $contrato->estado = EstadoContrato::Cerrado;
            $contrato->fecha_cierre = now();
            $contrato->save();
        }

        return $contrato;
    }

    private function tipoEsDeImpresion(LineaContrato $linea): bool
    {
        return $linea->tipo instanceof TipoLineaContrato && $linea->tipo->necesitaSeleccion();
    }

    private function tieneSeleccionConfirmada(Contrato $contrato): bool
    {
        return $contrato->sesiones()
            ->whereHas('seleccion', static function ($consulta): void {
                $consulta->where('estado', 'confirmada');
            })
            ->exists();
    }
}