<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cómo se pagó un abono.
 *
 * NO HAY VALOR POR OMISIÓN PARA "LO QUE SE HAGA"
 * ----------------------------------------------
 * Los medios de pago de un estudio son una decisión de la persona que atiende.
 * Un valor inventado pondría en los datos de la caja una etiqueta que nadie
 * usó, y en un estudio chico esa etiqueta es lo que después se contrasta contra
 * el banco.
 */
enum MetodoAbono: string
{
    case Efectivo = 'efectivo';
    case Transferencia = 'transferencia';
    case Tarjeta = 'tarjeta';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::Transferencia => 'Transferencia',
            self::Tarjeta => 'Tarjeta',
            self::Otro => 'Otro medio',
        };
    }

    /**
     * ¿Este medio deja un rastro que se pueda contrastar después?
     *
     * No es un permiso ni una advertencia: la pantalla lo muestra al lado del
     * abono para que quien audita sepa qué revisar. Ver docs/07.
     */
    public function dejaRastro(): bool
    {
        return $this === self::Transferencia || $this === self::Tarjeta;
    }
}