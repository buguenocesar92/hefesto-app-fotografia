<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué se hizo con un archivo original.
 *
 * LA BITÁCORA ES EL MOTIVO DE ESTA TABLA
 * ---------------------------------------
 * Los originales de las fotos de una familia son datos personales, y lo son más
 * todavía cuando la persona fotografiada es menor de edad. Por eso cada apertura
 * de un original queda anotada en `accesos_archivo`, permitida o denegada, con su
 * fecha. Ver docs/07.
 */
enum AccionAccesoArchivo: string
{
    case Consulta = 'consulta';
    case Descarga = 'descarga';
    case Seleccion = 'seleccion';
    case EnvioAlLaboratorio = 'envio_al_laboratorio';
    case Respaldo = 'respaldo';
    case Restauracion = 'restauracion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Consulta => 'Consulta',
            self::Descarga => 'Descarga',
            self::Seleccion => 'Selección',
            self::EnvioAlLaboratorio => 'Envío al laboratorio',
            self::Respaldo => 'Respaldo',
            self::Restauracion => 'Restauración',
        };
    }

    /**
     * ¿Esta acción mueve el archivo fuera del estudio?
     *
     * Enviar a un laboratorio externo significa sacar las fotos del lugar donde
     * el estudio las custodia. Por eso queda anotado aparte.
     */
    public function mueveElArchivo(): bool
    {
        return $this === self::Descarga
            || $this === self::EnvioAlLaboratorio
            || $this === self::Restauracion;
    }
}