<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Conservacion;
use Illuminate\Validation\Rule;

/**
 * Guardar un medio con originales.
 *
 * `tipo_medio` ES UNA LISTA CERRADA, Y NO POR ESTÉTICA
 * ---------------------------------------------------
 * Un texto libre acá permitiría escribir "en el escritorio de la recepción", que
 * es la forma de escribir una promesa de custodia que nadie puede cumplir. La
 * lista está en `App\Models\Conservacion::TIPOS_DE_MEDIO`, en PHP y no en la
 * base, porque es una decisión del software y no un dato del estudio.
 *
 * `ubicacion` ES LO ÚNICO QUE SE ACEPTA COMO TEXTO
 * -----------------------------------------------
 * Y aun así es texto libre. Se entendió: la ubicación es un detalle que cambia
 * con cada medio, y forzarla a un menú sería inventar una taxonomía que no
 * corresponde. Lo que no puede cambiar es el ESTADO, que es lo que el sistema
 * vigila. Ver docs/08.
 */
class GuardarConservacionRequest extends PeticionDeFotografia
{
    /** @return array<string, mixed> */
    public function reglas(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:160'],
            'tipo_medio' => ['required', Rule::in(Conservacion::TIPOS_DE_MEDIO)],
            'ubicacion' => ['required', 'string', 'max:500'],
            'estado' => ['required', Rule::in(['activa', 'desconectada', 'danada', 'destruida'])],
            'bytes_declarados' => ['nullable', 'integer', 'min:0'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function mensajes(): array
    {
        return parent::mensajes() + [
            'tipo_medio.in' => 'Ese tipo de medio no existe en el sistema. '
                . 'Agregar uno nuevo es una decisión sobre cómo se custodia un archivo de otra persona, '
                . 'y no se hace desde una pantalla.',
        ];
    }
}