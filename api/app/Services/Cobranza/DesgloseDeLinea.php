<?php

declare(strict_types=1);

namespace App\Services\Cobranza;

/**
 * Una línea ya contada: qué prometía, qué se entregó y qué falta.
 *
 * Es una de las piezas del DESGLOSE. Existe porque una cifra sola no contesta la
 * pregunta que hace el estudio: "dejé veinte de cien, ¿cuánto vale lo que falta?".
 */
final class DesgloseDeLinea
{
    public function __construct(
        public readonly string $concepto,
        public readonly int $cantidadPactada,
        public readonly int $cantidadEntregada,
        public readonly int $precioUnitario,
        public readonly ?string $unidad = null,
        public readonly ?string $tipo = null,
        public readonly ?string $lineaId = null,
    ) {}

    /** Lo que la línea promete, en pesos. */
    public function pactado(): int
    {
        return $this->cantidadPactada * $this->precioUnitario;
    }

    /** Lo que la línea entregó, en pesos. */
    public function entregado(): int
    {
        return $this->cantidadEntregada * $this->precioUnitario;
    }

    /** Cuántas unidades faltan. Nunca negativo. */
    public function cantidadPendiente(): int
    {
        return max(0, $this->cantidadPactada - $this->cantidadEntregada);
    }

    /** Cuánto vale lo que falta, en pesos. Nunca negativo. */
    public function valorPendiente(): int
    {
        return $this->cantidadPendiente() * $this->precioUnitario;
    }

    /** ¿Está completa? */
    public function estaCompleta(): bool
    {
        return $this->cantidadPendiente() === 0;
    }

    /** ¿Se entregó de más? */
    public function tieneExceso(): bool
    {
        return $this->cantidadEntregada > $this->cantidadPactada;
    }

    /**
     * Cuánto se entregó de más, en unidades. Cero si no hay exceso.
     *
     * Se informa aparte y no se descuenta del pendiente: si una persona eligió
     * quince fotos más que las prometidas, eso es una decisión del estudio y no
     * una corrección silenciosa de la cuenta.
     */
    public function unidadesDeExceso(): int
    {
        return max(0, $this->cantidadEntregada - $this->cantidadPactada);
    }

    /** @return array<string, int|string|null|bool> */
    public function aArray(): array
    {
        return [
            'linea_id' => $this->lineaId,
            'concepto' => $this->concepto,
            'tipo' => $this->tipo,
            'unidad' => $this->unidad,
            'cantidad_pactada' => $this->cantidadPactada,
            'cantidad_entregada' => $this->cantidadEntregada,
            'cantidad_pendiente' => $this->cantidadPendiente(),
            'unidades_de_exceso' => $this->unidadesDeExceso(),
            'precio_unitario' => $this->precioUnitario,
            'pactado' => $this->pactado(),
            'entregado' => $this->entregado(),
            'valor_pendiente' => $this->valorPendiente(),
            'completa' => $this->estaCompleta(),
        ];
    }
}