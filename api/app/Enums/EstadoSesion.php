<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una sesión de fotografía.
 *
 * POR QUÉ LA FECHA PROMETIDA ES EL EJE
 * -------------------------------------
 * Lo que un estudio vende es una fecha que no puede fallar: el cumple, el
 * matrimonio, el bautismo. Por eso estos cuatro estados dicen, en orden, qué tan
 * cerca está esa fecha de cumplirse.
 *
 * `pendiente_seleccion` NO ES UN ERROR
 * ------------------------------------
 * Es el estado NORMAL de una sesión que se realizó y en la que la persona todavía
 * no eligió. El sistema lo trata como estado de trabajo: aparece en su lista,
 * tiene su fecha y no dispara ningún error. Tratarlo como falla haría que el
 * equipo aprendiera a ignorar la pantalla. Ver docs/03.
 *
 * Y de ahí sale la regla más importante de esta aplicación: una sesión NO pasa
 * a `entregada` sin una selección `confirmada`. Ver
 * App\Services\Sesiones\GestorDeEstadoDeSesion.
 */
enum EstadoSesion: string
{
    case Agendada = 'agendada';
    case Realizada = 'realizada';
    case PendienteSeleccion = 'pendiente_seleccion';
    case Entregada = 'entregada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Agendada => 'Agendada',
            self::Realizada => 'Realizada',
            self::PendienteSeleccion => 'Pendiente de selección',
            self::Entregada => 'Entregada',
        };
    }

    /** ¿La sesión ya se tomó y ya tiene fotos? */
    public function estaTomada(): bool
    {
        return $this !== self::Agendada;
    }

    /**
     * ¿La sesión está esperando a que la persona elija?
     *
     * Es el único estado en que el trabajo está parado esperando una decisión de
     * la persona, y no del estudio.
     */
    public function esperaDecisionDeLaPersona(): bool
    {
        return $this === self::PendienteSeleccion;
    }

    /** ¿La fecha prometida ya pasó y la sesión no está entregada? */
    public function estaAtrasada(?\DateTimeInterface $hoy = null): bool
    {
        return $this !== self::Entregada;
    }
}