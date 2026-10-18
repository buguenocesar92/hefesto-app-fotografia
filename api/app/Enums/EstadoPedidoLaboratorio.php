<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de un pedido a un laboratorio de impresión.
 *
 * ESTE ES UN SUBCONTRATO, NO UNA VENTA
 * -------------------------------------
 * El estudio manda las fotos afuera y espera que vuelvan. Cada estado dice algo
 * sobre un trabajo que el estudio no controla, y por eso el sistema lleva la
 * fecha prometida de recepción al lado de cada uno.
 *
 * `recibido_con_fallos` existe porque es lo que pasa siempre: el laboratorio
 * devuelve parte del trabajo y avisa que el resto no. Registrar eso como un todo
 * recibido sería mentir sobre lo que se le entregó a la persona.
 */
enum EstadoPedidoLaboratorio: string
{
    case Borrador = 'borrador';
    case Enviado = 'enviado';
    case EnLaboratorio = 'en_laboratorio';
    case Recibido = 'recibido';
    case RecibidoConFallos = 'recibido_con_fallos';
    case Anulado = 'anulado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Enviado => 'Enviado',
            self::EnLaboratorio => 'En el laboratorio',
            self::Recibido => 'Recibido completo',
            self::RecibidoConFallos => 'Recibido con faltantes',
            self::Anulado => 'Anulado',
        };
    }

    /** ¿El trabajo ya volvió al estudio, completo o con faltantes? */
    public function fueRecibido(): bool
    {
        return $this === self::Recibido || $this === self::RecibidoConFallos;
    }

    /** ¿Hay un faltante que el estudio todavía tiene que resolver? */
    public function tieneFaltantes(): bool
    {
        return $this === self::RecibidoConFallos;
    }

    /**
     * ¿El pedido ya salió del estudio?
     *
     * Un pedido en `borrador` todavía se puede cambiar por completo. Uno ya
     * enviado es un trabajo que alguien más está haciendo.
     */
    public function salioDelEstudio(): bool
    {
        return $this !== self::Borrador && $this !== self::Anulado;
    }

    /** ¿Se puede cambiar el contenido del pedido? */
    public function admiteCambioDeContenido(): bool
    {
        return $this === self::Borrador;
    }
}