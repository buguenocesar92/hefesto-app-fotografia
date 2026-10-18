<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de la selección de una sesión.
 *
 * POR QUÉ `confirmada` ES LA QUE PERMITE ENTREGAR
 * ----------------------------------------------
 * `pendiente` es lo normal: la sesión se realizó y nadie ha abierto la galería.
 * `en_curso` es que la persona está mirando, o Marquó algunas y volvió. Recién
 * `confirmada` hay una decisión: estas son las fotos.
 *
 * Hasta `confirmada`, la sesión NO se puede marcar entregada. Eso es lo que
 * impide imprimir fotos que nadie eligió, que es el error caro del rubro: se
 * paga la impresión y después nadie quiere el resultado. Ver
 * App\Services\Sesiones\GestorDeEstadoDeSesion.
 */
enum EstadoSeleccion: string
{
    case Pendiente = 'pendiente';
    case EnCurso = 'en_curso';
    case Confirmada = 'confirmada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Sin empezar',
            self::EnCurso => 'En curso',
            self::Confirmada => 'Confirmada',
        };
    }

    /** ¿Hay una decisión firme de la persona sobre qué se imprime? */
    public function estaConfirmada(): bool
    {
        return $this === self::Confirmada;
    }
}