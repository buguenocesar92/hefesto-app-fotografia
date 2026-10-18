<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Registrar la selección de una sesión.
 *
 * `confirmar` ES LO QUE ABRE LA PUERTA A ENTREGAR
 * ----------------------------------------------
 * Guardar sin confirmar deja la sesión en `realizada`: se tomó, nadie ha
 * elegido. Confirmar la deja en `pendiente_seleccion` y recién ahí el sistema
 * permite marcar la sesión como entregada.
 *
 * El sistema NO valida que las fotos marcadas pertenezcan a la sesión: eso lo
 * comprueba `App\Services\Seleccion\RegistradorDeSeleccion`, que tiene que leer
 * la base. Acá solo se valida la forma. Ver docs/03.
 */
class RegistrarSeleccionRequest extends PeticionDeFotografia
{
    /** @return array<string, mixed> */
    public function reglas(): array
    {
        return [
            'fotos' => ['present', 'array'],
            'fotos.*' => ['uuid', 'distinct'],
            'confirmar' => ['sometimes', 'boolean'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function mensajes(): array
    {
        return parent::mensajes() + [
            'fotos.present' => 'Hay que enviar la lista de fotos elegidas, aunque esté vacía.',
            'fotos.*.distinct' => 'La misma foto no puede aparecer dos veces en la selección.',
        ];
    }
}