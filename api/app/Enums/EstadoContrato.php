<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de un contrato con paquete.
 *
 * EL ESTADO QUE IMPORTA ES `activo`
 * --------------------------------
 * Un contrato en `borrador` todavía no ocupa agenda: se puede arreglar sin
 * consecuencias. Uno en `activo` promete: hay sesiones con fecha, hay fotos que
 * se van a imprimir y hay plata que falta cobrar.
 *
 * `cerrado` solo se alcanza cuando todo lo pactado está entregado. Una entrega
 * parcial NO lo cierra. Ver App\Services\Contratos\RegistradorDeEntrega.
 */
enum EstadoContrato: string
{
    case Borrador = 'borrador';
    case Activo = 'activo';
    case Cerrado = 'cerrado';
    case Anulado = 'anulado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Activo => 'Activo',
            self::Cerrado => 'Cerrado',
            self::Anulado => 'Anulado',
        };
    }

    /** ¿Se pueden agregar sesiones y entregas a un contrato en este estado? */
    public function admiteTrabajo(): bool
    {
        return $this === self::Activo;
    }

    /** ¿La fecha de sesión todavía se puede mover? */
    public function admiteCambioDeFecha(): bool
    {
        return $this === self::Borrador || $this === self::Activo;
    }
}