<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Los tres roles con los que trabaja un estudio fotográfico.
 *
 * POR QUÉ TRES Y NO MÁS
 * ---------------------
 * El estudio es chico. Lo que existe son tres personas distintas con tres
 * trabajos distintos: quien atiende el teléfono y agenda, quien toma las fotos y
 * quien administra. Un cuarto rol sería una manera de no decidir quién hace
 * qué.
 *
 * EL ROL DICE CÓMO SE ORGANIZA EL ESTUDIO, NO QUÉ SE PUEDE HACER
 * --------------------------------------------------------------
 * Lo que se puede hacer sale de `App\Services\Permisos\CapacidadesDeUsuario`.
 * El rol se vuelve a leer de Authentik en cada ingreso, así que el rol guardado
 * es una copia de trabajo y la fuente de verdad está afuera. Ver docs/03.
 */
enum RolEstudio: string
{
    case Recepcion = 'recepcion';
    case Fotografo = 'fotografo';
    case Administracion = 'administracion';

    /** Lo que se muestra en pantalla. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Recepcion => 'Recepción',
            self::Fotografo => 'Fotografía',
            self::Administracion => 'Administración',
        };
    }

    /** El equipo de Authentik que declara este rol, si no se configuró otro. */
    public function equipoAuthentik(): string
    {
        return match ($this) {
            self::Recepcion => 'Recepción de Fotografía',
            self::Fotografo => 'Fotografía de Hefesto',
            self::Administracion => 'Administración de Fotografía',
        };
    }

    /** ¿Es el rol que administra el estudio? */
    public function administra(): bool
    {
        return $this === self::Administracion;
    }
}