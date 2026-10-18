<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\MetodoAbono;
use Illuminate\Validation\Rule;

/**
 * Registrar un abono.
 *
 * `monto` ES ENTERO Y POSITIVO
 * ----------------------------
 * Nunca decimal. Un saldo mal redondeado se convierte en una diferencia de caja
 * que nadie sabe de dónde salió, y en un estudio chico esa diferencia se come el
 * trabajo de un mes.
 *
 * LO QUE ESTE REQUEST NO COMPRUEBA
 * --------------------------------
 * Que el abono no pase lo pactado. Eso lo comprueba
 * `App\Services\Cobranza\CalculadoraDeSaldo`, que es donde vive el saldo. Ver
 * docs/02.
 */
class RegistrarAbonoRequest extends PeticionDeFotografia
{
    /** @return array<string, mixed> */
    public function reglas(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:32'],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'monto' => ['required', 'integer', 'min:1'],
            'metodo' => ['required', Rule::enum(MetodoAbono::class)],
            'referencia' => ['nullable', 'string', 'max:120'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function mensajes(): array
    {
        return parent::mensajes() + [
            'monto.min' => 'El monto del abono tiene que ser mayor que cero.',
            'fecha.before_or_equal' => 'Un abono no puede tener fecha futura: o se registró mal la fecha, '
                . 'o se quiere dejar una deuda ya cobrada.',
        ];
    }
}