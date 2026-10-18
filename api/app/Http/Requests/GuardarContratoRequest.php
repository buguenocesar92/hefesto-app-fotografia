<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Abrir o cambiar un contrato con paquete.
 *
 * POR QUÉ EL PRECIO VIENE EN EL CUERPO Y NO DEL PAQUETE
 * -----------------------------------------------------
 * Porque lo que se firma se congela acá. El paquete puede cambiar de precio la
 * semana que viene; este contrato tiene que seguir valiendo lo que valía cuando
 * la persona firmó. Ver docs/06.
 *
 * POR QUÉ LAS LÍNEAS SON OBLIGATORIAS
 * -----------------------------------
 * Porque un contrato sin líneas no se puede desglosar y no se puede cerrar. Es
 * la diferencia entre "un paquete que todavía no existe" y "nada".
 */
class GuardarContratoRequest extends PeticionDeFotografia
{
    /** @return array<string, mixed> */
    public function reglas(): array
    {
        return [
            'cliente_id' => ['required', 'uuid'],
            'paquete_id' => ['nullable', 'uuid'],
            'codigo' => ['required', 'string', 'max:32'],
            'fecha_contrato' => ['required', 'date', 'before_or_equal:today'],
            'precio_total' => ['required', 'integer', 'min:0'],
            'estado' => ['required', Rule::in(['borrador', 'activo'])],
            'dias_promesa_entrega' => ['nullable', 'integer', 'min:0'],
            'notas' => ['nullable', 'string', 'max:2000'],

            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.tipo' => ['required', 'string'],
            'lineas.*.concepto' => ['required', 'string', 'max:200'],
            'lineas.*.cantidad_pactada' => ['required', 'integer', 'min:1'],
            'lineas.*.precio_unitario' => ['required', 'integer', 'min:0'],
            'lineas.*.unidad_minutos' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function mensajes(): array
    {
        return parent::mensajes() + [
            'lineas.required' => 'Un contrato tiene que tener al menos una línea de entrega. '
                . 'Sin líneas no hay nada que desglosar ni que cerrar.',
            'lineas.min' => 'Un contrato tiene que tener al menos una línea de entrega.',
            'estado.in' => 'Un contrato nuevo solo puede quedar en borrador o activo.',
        ];
    }
}