<?php

declare(strict_types=1);

namespace App\Services\Cobranza;

/**
 * Una línea de contrato, en forma plana, para contar.
 *
 * POR QUÉ ES UNA ESTRUCTURA PROPIA Y NO UN MODELO
 * ----------------------------------------------
 * Porque la calculadora NO debe tocar la base de datos. Es la razón de que su
 * prueba sea una prueba unitaria de verdad: si el cálculo leyera modelos de
 * Eloquent, habría que levantar PostgreSQL para probarlo, y una prueba que
 * necesita la base no se corre.
 *
 * Ver `CalculadoraDeSaldo`.
 */
final class LineaAContar
{
    public function __construct(
        public readonly string $concepto,
        public readonly int $cantidadPactada,
        public readonly int $cantidadEntregada,
        public readonly int $precioUnitario,
        /** Para la línea de sesión: minutos. Para las demás: unidades. */
        public readonly ?string $unidad = null,
        /** El tipo de línea, tal como lo escribe el enum. */
        public readonly ?string $tipo = null,
        /** El identificador de la línea, para poder volatilarla en pantalla. */
        public readonly ?string $lineaId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $atributos
     */
    public static function desde(array $atributos): self
    {
        return new self(
            concepto: (string) ($atributos['concepto'] ?? ''),
            cantidadPactada: (int) ($atributos['cantidad_pactada'] ?? 0),
            cantidadEntregada: (int) ($atributos['cantidad_entregada'] ?? 0),
            precioUnitario: (int) ($atributos['precio_unitario'] ?? 0),
            unidad: isset($atributos['unidad']) ? (string) $atributos['unidad'] : null,
            tipo: isset($atributos['tipo']) ? (string) $atributos['tipo'] : null,
            lineaId: isset($atributos['id']) ? (string) $atributos['id'] : null,
        );
    }
}