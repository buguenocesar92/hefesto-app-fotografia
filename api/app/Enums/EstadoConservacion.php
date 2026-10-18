<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una conservación, es decir, de un medio con originales.
 *
 * POR QUÉ NO ES UN TEXTO LIBRE
 * -----------------------------
 * Un estudio entrega las fotos "de por vida". Si el medio se muere, se pierden
 * las sesiones de tres años, y esa es la pérdida más grande del rubro. Un campo
 * de texto libre con la ruta del disco no avisa de nada: el disco se puede caer
 * y el texto sigue ahí, diciendo que todo está bien.
 *
 * Por eso el estado es un dato del dominio, se puede consultar, y dispara el
 * trabajo `VerificarConservacionJob`. Ver docs/02 y docs/08.
 */
enum EstadoConservacion: string
{
    case Activa = 'activa';
    case Desconectada = 'desconectada';
    case Danada = 'danada';
    case Destruida = 'destruida';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Activa => 'Activa',
            self::Desconectada => 'Desconectada',
            self::Danada => 'Con daño',
            self::Destruida => 'Destruida',
        };
    }

    /**
     * ¿Se pueden seguir entregando fotos que están acá?
     *
     * `desconectada` NO cuenta. Es la diferencia entre "no sé dónde está" y
     * "está y funciona": un disco desconectado se puede reconectar, uno dañado
     * ya no.
     */
    public function estaOperativa(): bool
    {
        return $this === self::Activa;
    }

    /** ¿El medio está perdido de una vez y no volverá? */
    public function esPerdida(): bool
    {
        return $this === self::Destruida;
    }

    /** ¿El estado exige una revisión inmediata por parte de una persona? */
    public function exigeRevision(): bool
    {
        return $this !== self::Activa;
    }
}