<?php

declare(strict_types=1);

namespace App\Services\Laboratorio;

use App\Enums\EstadoPedidoLaboratorio;
use App\Models\PedidoLaboratorio;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Los estados de un encargo a un laboratorio externo.
 *
 * POR QUÉ ESTO ES MÁS RIGUROSO QUE UN CARRITO
 * --------------------------------------------
 * Un pedido en `borrador` todavía se puede cambiar por completo. Uno ya `enviado`
 * es un trabajo que OTRO está haciendo: el contenido se congela, porque el
 * laboratorio puede haber impreso ya, y cambiar la cantidad sin avisar produce
 * una factura que no cuadra con lo entregado.
 *
 * POR QUÉ EL RECIBO ADMITE FALTANTES
 * ---------------------------------
 * Porque es lo que pasa siempre: el laboratorio devuelve cuarenta y nueve de
 * cincuenta. Registrar el pedido como recibido completo sería mentir sobre lo que
 * se le entregó a la persona, y el faltante se descubriría cuando la familia venga
 * a buscar. Ver App\Enums\EstadoPedidoLaboratorio.
 */
class SeguimientoDePedidos
{
    /** Marca el pedido como enviado. Congela el contenido. */
    public function enviar(PedidoLaboratorio $pedido, \DateTimeInterface $fechaEnvio): PedidoLaboratorio
    {
        if (! $pedido->estado->admiteCambioDeContenido()) {
            throw new DomainException(sprintf(
                'Un pedido %s ya no se puede enviar: el contenido se congeló cuando salió del estudio.',
                $pedido->estado->etiqueta()
            ));
        }

        if ($pedido->items()->count() === 0) {
            throw new DomainException('No se puede enviar un pedido sin ítems. Un encargo vacío no se puede cobrar.');
        }

        $pedido->fecha_envio = $fechaEnvio;
        $pedido->estado = EstadoPedidoLaboratorio::Enviado;
        $pedido->save();

        return $pedido;
    }

    /**
     * Recibe el trabajo del laboratorio.
     *
     * `$recibidasPorId` es el mapa de `id del ítem` a `cantidad que volvió`. El
     * estado final lo decide el cálculo, no quien aprieta el botón: si todos los
     * ítems volvieron completos, `recibido`; si quedó algo, `recibido_con_fallos`.
     */
    public function recibir(
        PedidoLaboratorio $pedido,
        array $recibidasPorId,
        \DateTimeInterface $fechaRecepcion,
        ?string $observaciones = null,
    ): PedidoLaboratorio {
        if (! $pedido->estado->salioDelEstudio()) {
            throw new DomainException('No se puede recibir un pedido que no salió del estudio.');
        }

        $idsConocidos = $pedido->items()->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $desconocidos = array_diff(array_keys($recibidasPorId), $idsConocidos);

        if ($desconocidos !== []) {
            throw new DomainException('Hay cantidades recibidas de ítems que no son de este pedido.');
        }

        $quedanFaltantes = false;

        foreach ($pedido->items()->get() as $item) {
            $recibida = (int) ($recibidasPorId[(string) $item->id] ?? 0);

            if ($recibida > $item->cantidad_pedida) {
                throw new DomainException(sprintf(
                    'El ítem «%s» de %d unidad(es) no puede haber recibido %d: se recibieron más de las pedidas.',
                    $item->formato,
                    $item->cantidad_pedida,
                    $recibida
                ));
            }

            $item->cantidad_recibida = $recibida;
            $item->save();

            if (! $item->estaCompleto()) {
                $quedanFaltantes = true;
            }
        }

        $pedido->fecha_recepcion_real = $fechaRecepcion;
        $pedido->estado = $quedanFaltantes
            ? EstadoPedidoLaboratorio::RecibidoConFallos
            : EstadoPedidoLaboratorio::Recibido;

        if ($observaciones !== null) {
            $pedido->observaciones = $observaciones;
        }

        $pedido->save();

        return $pedido;
    }

    /**
     * Los pedidos cuya fecha prometida AL CLIENTE ya pasó.
     *
     * @param  Collection<int, PedidoLaboratorio>  $pedidos
     * @return list<PedidoLaboratorio>
     */
    public function vencidos(Collection $pedidos, \DateTimeInterface $hoy = null): array
    {
        $hoy = $hoy ?? today();

        return $pedidos
            ->filter(static fn (PedidoLaboratorio $p): bool => $p->estaVencido($hoy))
            ->values()
            ->all();
    }
}