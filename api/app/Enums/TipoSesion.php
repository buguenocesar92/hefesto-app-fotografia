<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de sesión que el estudio agenda.
 *
 * POR QUÉ ES UN DATO DEL DOMINIO Y NO UN TEXTO
 * -------------------------------------------
 * El tipo cambia lo que el sistema recuerda hacer: un retrato tiene una fecha
 * prometida y una confirmación de selección; un matrimonio tiene además la
 * lista de personas a las que hay que tener los consentimientos.
 *
 * Es una lista CERRADA a propósito. Un tipo libre terminaría teniendo veinte
 * variantes mal escritas y ninguna que se pueda filtrar.
 *
 * `otro` existe para lo que el estudio todavía no le ha puesto nombre, y su
 * etiqueta en pantalla se completa con el detalle que escribe quien agenda.
 */
enum TipoSesion: string
{
    case Cumpleanos = 'cumpleanos';
    case Matrimonio = 'matrimonio';
    case Bautismo = 'bautismo';
    case Retrato = 'retrato';
    case Familiar = 'familiar';
    case Corporativo = 'corporativo';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Cumpleanos => 'Cumpleaños',
            self::Matrimonio => 'Matrimonio',
            self::Bautismo => 'Bautismo',
            self::Retrato => 'Retrato',
            self::Familiar => 'Sesión familiar',
            self::Corporativo => 'Corporativo',
            self::Otro => 'Otro',
        };
    }

    /**
     * ¿En este tipo hay alguien que no es el cliente y cuya imagen se guarda?
     *
     * En un cumple o un bautismo aparecen niños. La respuesta activa el aviso de
     * consentimiento en la ficha, no bloquea el trabajo: el bloqueo lo pone la
     * Ley y el estudio, no este enum. Ver docs/07.
     */
    public function puedeIncluirMenores(): bool
    {
        return $this === self::Cumpleanos
            || $this === self::Matrimonio
            || $this === self::Bautismo
            || $this === self::Familiar;
    }

    /** ¿La fecha prometida de entrega es la más frágil de todas? */
    public function esFechaCritica(): bool
    {
        return $this === self::Cumpleanos
            || $this === self::Matrimonio
            || $this === self::Bautismo;
    }
}