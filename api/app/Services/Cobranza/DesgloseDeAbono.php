<?php

declare(strict_types=1);

namespace App\Services\Cobranza;

/**
 * Un abono ya contado, para mostrarlo al lado del total.
 *
 * Va en el desglose porque una pregunta de caja casi nunca es "¿cuánto me deben
 * en total?", sino "¿cuándo me lo pagaron y con qué me lo pagó?".
 */
final class DesgloseDeAbono
{
    public function __construct(
        public readonly string $codigo,
        public readonly int $monto,
        public readonly string $fecha,
        public readonly string $metodo,
        public readonly ?string $referencia = null,
        public readonly bool $dejaRastro = false,
        public readonly ?string $abonoId = null,
    ) {}
}