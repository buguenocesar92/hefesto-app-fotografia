<?php

declare(strict_types=1);

namespace App\Services\Cobranza;

/**
 * Todo lo que hay que saber de un contrato para calcular su saldo.
 *
 * Es una estructura de ENTRADA, plana y sin base de datos. Quien la arma es
 * `ServicioDeSaldo`, que sí lee modelos; quien la usa es `CalculadoraDeSaldo`,
 * que no toca nada.
 *
 * POR QUÉ `precioContrato` Y NO SOLO LAS LÍNEAS
 * ---------------------------------------------
 * Porque el precio que firmó la persona es el del contrato, no la suma de las
 * líneas. Puede haber descuento, puede haber un recargo por el lugar, puede
 * haber un abono fijo. El desglose muestra las dos cifras para que la diferencia
 * se vea, en vez de esconderla dentro de una suma.
 */
final class CobroAContar
{
    /**
     * @param  list<LineaAContar>  $lineas
     * @param  list<AbonoContado>  $abonos
     * @param  list<string>  $observaciones  por qué falta algo, en palabras
     */
    public function __construct(
        public readonly string $contratoId,
        public readonly string $codigo,
        public readonly int $precioContrato,
        public readonly array $lineas = [],
        public readonly array $abonos = [],
        public readonly array $observaciones = [],
    ) {}
}