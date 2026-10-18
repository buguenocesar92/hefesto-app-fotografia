<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué promete una línea de un contrato.
 *
 * POR QUÉ EL PAQUETE SE ABRE EN LÍNEAS
 * ------------------------------------
 * Un paquete no es un precio: es una lista de cosas. "Sesión de dos horas y cien
 * fotos" son dos líneas, y cada una se puede entregar por separado y en un
 * momento distinto. La línea es lo que permite decir, en cualquier momento,
 * cuánto se entregó y cuánto falta.
 *
 * `foto_impresa` y `album` son cosas que el estudio ENCARGA a un laboratorio
 * externo: no las imprime, las subcontrata. Por eso su entrega se puede quedar
 * esperando la recepción del laboratorio. Ver docs/02.
 */
enum TipoLineaContrato: string
{
    case Sesion = 'sesion';
    case FotoEntregada = 'foto_entregada';
    case Impresion = 'impresion';
    case Album = 'album';
    case ArchivoDigital = 'archivo_digital';
    case Adicional = 'adicional';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Sesion => 'Sesión',
            self::FotoEntregada => 'Fotos tratadas',
            self::Impresion => 'Impresión',
            self::Album => 'Álbum',
            self::ArchivoDigital => 'Archivo digital',
            self::Adicional => 'Adicional',
        };
    }

    /**
     * ¿La línea depende de que un laboratorio externo devuelva el trabajo?
     *
     * Es la diferencia entre una línea que el estudio cumple con su propio
     * equipo y una que no puede cumplir hasta que el laboratorio conteste.
     */
    public function dependeDelLaboratorio(): bool
    {
        return $this === self::Impresion || $this === self::Album;
    }

    /**
     * ¿La línea necesita una selección de fotos confirmada antes de entregarse?
     *
     * Las impresiones y el álbum, sí: no se imprime nada que la persona no haya
     * elegido. Una sesión ya tomó sus fotos sin que nadie eligiera.
     */
    public function necesitaSeleccion(): bool
    {
        return $this === self::Impresion || $this === self::Album;
    }
}