<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoPedidoLaboratorio;
use Illuminate\Validation\Rule;

/**
 * Abrir o cambiar un pedido a un laboratorio externo.
 *
 * POR QUÉ `formato` ES TEXTO Y NO UNA LISTA
 * -----------------------------------------
 * Porque cada laboratorio ofrece formatos distintos y los precios cambian por
 * temporada. Si esta aplicación trajera una lista de formatos, sería una lista
 * que es falsa en cuatro de cada cinco laboratorios, y el precio de cada uno
 * sería un dato inventado. Ver config/fotografia.php y docs/05.
 *
 * `fecha_prometida_recepcion` ES EL COMPROMISO CON EL CLIENTE
 * ----------------------------------------------------------
 * No con el laboratorio. El plazo que se le pide al laboratorio va aparte, en
 * `dias_plazo_laboratorio`, y son dos promesas distintas.
 */
class GuardarPedidoLaboratorioRequest extends PeticionDeFotografia
{
    /** @return array<string, mixed> */
    public function reglas(): array
    {
        return [
            'laboratorio_id' => ['required', 'uuid'],
            'sesion_id' => ['nullable', 'uuid'],
            'contrato_id' => ['nullable', 'uuid'],
            'codigo' => ['required', 'string', 'max:32'],

            'fecha_prometida_recepcion' => ['nullable', 'date'],
            'dias_plazo_laboratorio' => ['nullable', 'integer', 'min:0'],

            'estado' => ['required', Rule::enum(EstadoPedidoLaboratorio::class)],

            'observaciones' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.tipo' => ['required', 'string', 'max:24'],
            'items.*.formato' => ['required', 'string', 'max:60'],
            'items.*.cantidad_pedida' => ['required', 'integer', 'min:1'],
            'items.*.precio_unitario' => ['required', 'integer', 'min:0'],
            'items.*.seleccion_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function mensajes(): array
    {
        return parent::mensajes() + [
            'items.required' => 'Un pedido a laboratorio sin ítems no se puede enviar: no hay nada que encargar.',
            'items.min' => 'Un pedido a laboratorio necesita al menos un ítem.',
        ];
    }
}