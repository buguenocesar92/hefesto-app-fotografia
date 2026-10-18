<?php

declare(strict_types=1);

namespace App\Services\Cobranza;

/**
 * Un abono, en forma plana, para contar.
 *
 * `dejaRastro` viene adentro y no se deduce acá: es el enum el que sabe si el
 * medio de pago deja un comprobante que se pueda contrastar después. La
 * calculadora solo lo copia al desglose. Ver `App\Enums\MetodoAbono`.
 */
final class AbonoContado
{
    public function __construct(
        public readonly string $codigo,
        public readonly int $monto,
        /** Fecha en formato `AAAA-MM-DD`. */
        public readonly string $fecha,
        public readonly string $metodo,
        public readonly ?string $referencia = null,
        public readonly bool $dejaRastro = false,
        public readonly ?string $abonoId = null,
    ) {}
}