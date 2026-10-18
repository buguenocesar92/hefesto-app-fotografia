<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * Error del dominio de Fotografía.
 *
 * POR QUÉ ES UNA CLASE PROPIA Y NO UN `DomainException` SUELTO
 * ----------------------------------------------------------
 * Para que el archivo que lo lanza diga de qué habla. Un `DomainException` a pelo
 * puede ser cualquier cosa, y en un sistema donde los errores de negocio se leen
 * en pantalla —"esa sesión no tiene selección confirmada"—, saber de dónde salió
 * la diferencia entre un error previsto y uno de paso.
 *
 * Y hereda de `DomainException` a propósito: es la clase que `bootstrap/app.php`
 * traduce a 422. Un error de infraestructura sigue siendo 500, porque tiene que
 * verse como lo que es. Ver docs/01.
 */
class ErrorDeFotografia extends DomainException
{
    public static function sesionSinSeleccion(string $titulo): self
    {
        return new self(
            'La sesión «' . $titulo . '» no tiene una selección confirmada. '
            . 'Hasta que la persona elija no hay nada que imprimir.'
        );
    }

    public static function entregaSinSeleccion(string $concepto): self
    {
        return new self(
            'La línea «' . $concepto . '» no se puede dar por entregada sin una selección confirmada. '
            . 'Imprimir es un encargo a un laboratorio externo, se paga por adelantado y no se devuelve.'
        );
    }

    public static function entregaQueSuperaLoPactado(string $concepto, int $pactada, int $entregada): self
    {
        return new self(
            sprintf(
                'La línea «%s» promete %d y ya lleva %d entregadas. Pasarse es trabajo extra y lo tiene que autorizar una persona.',
                $concepto,
                $pactada,
                $entregada
            )
        );
    }

    public static function medioNoOperativo(string $nombre, string $estado): self
    {
        return new self(
            'Los originales de esta sesión están en «' . $nombre . '», que está ' . $estado . '. '
            . 'Antes de entregar hay que verificar ese medio.'
        );
    }

    public static function seleccionSinFotos(): self
    {
        return new self('No se puede confirmar una selección sin ninguna foto elegida.');
    }
}