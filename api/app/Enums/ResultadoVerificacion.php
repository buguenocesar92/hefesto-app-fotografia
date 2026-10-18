<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Resultado de verificar que un medio con originales sigue vivo.
 *
 * `no_se_pudo_verificar` NO ES LO MISMO QUE UN FALLO, y la diferencia importa:
 * no se pudo verificar cuando el equipo estaba en otra ciudad, cuando el
 * estudio está cerrado o cuando nadie ha ido a mirar. Registrar eso como fallo
 * haría que el estudio se cansara de las alertas y dejara de mirarlas.
 */
enum ResultadoVerificacion: string
{
    case Correcta = 'correcta';
    case ConFaltantes = 'con_faltantes';
    case NoSePudoVerificar = 'no_se_pudo_verificar';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Correcta => 'Correcta',
            self::ConFaltantes => 'Con faltantes',
            self::NoSePudoVerificar => 'No se pudo verificar',
        };
    }

    /** ¿Se comprobó de verdad que los archivos están? */
    public function fueComprobada(): bool
    {
        return $this === self::Correcta || $this === self::ConFaltantes;
    }

    /** ¿Algo no estaba donde debía estar? */
    public function tieneProblemas(): bool
    {
        return $this === self::ConFaltantes;
    }
}