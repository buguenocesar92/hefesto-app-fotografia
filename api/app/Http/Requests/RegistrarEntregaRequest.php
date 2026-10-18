<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Registrar una entrega parcial.
 *
 * `cantidad` ES LO QUE SE ENTREGA AHORA, NO EL TOTAL
 * --------------------------------------------------
 * Es la diferencia entre una entrega parcial y un sobrescrito. Si el campo fuera
 * el total, el primer registro dejaría la línea en el número que la pantalla ya
 * traía, y la segunda entrega se perdería.
 *
 * La validación del "no pases de lo pactado" NO está acá a propósito: depende del
 * valor actual de la línea en la base, y la hace
 * `App\Services\Contratos\RegistradorDeEntrega`, que es el que escribe.
 */
class RegistrarEntregaRequest extends PeticionDeFotografia
{
    /** @return array<string, mixed> */
    public function reglas(): array
    {
        return [
            'linea_id' => ['required', 'uuid'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function mensajes(): array
    {
        return parent::mensajes() + [
            'cantidad.min' => 'La cantidad entregada tiene que ser mayor que cero.',
        ];
    }
}