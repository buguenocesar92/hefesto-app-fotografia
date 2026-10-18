<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TipoSesion;
use Illuminate\Validation\Rule;

/**
 * Agendar una sesión.
 *
 * POR QUÉ SE PIDE LA FECHA Y NO EL PLAZO
 * ---------------------------------------
 * La fecha prometida es el compromiso, y se escribe una sola vez. El plazo son
 * los días entre la sesión y la entrega, y ese SÍ sale de
 * `config('fotografia.entrega.dias_promesa_por_omision')`, que nace en `null`.
 *
 * Con `null` no hay fecha prometida de entrega, y la pantalla lo declara en vez
 * de mostrar una fecha que el sistema se inventó. Ver docs/06.
 *
 * POR QUÉ SE VALIDA LA ANTICIPACIÓN
 * ---------------------------------
 * Porque es un límite técnico del widget de fechas, no una promesa. Agendar para
 * dentro de tres años casi siempre es un error de tipeo, y un cumple que se
 * agenda en 2031 termina en una sesión agendada que nadie recuerda.
 */
class AgendarSesionRequest extends PeticionDeFotografia
{
    /** @return array<string, mixed> */
    public function reglas(): array
    {
        $hoy = now()->startOfDay();

        $anticipacionMaxima = (int) config('fotografia.agenda.anticipacion_maxima_dias');
        $anticipacionMinima = (int) config('fotografia.agenda.anticipacion_minima_dias');

        return [
            'cliente_id' => ['required', 'uuid'],
            'contrato_id' => ['nullable', 'uuid'],
            'fotografo_id' => ['nullable', 'uuid'],

            'tipo' => ['required', Rule::enum(TipoSesion::class)],
            'titulo' => ['required', 'string', 'max:200'],
            'lugar' => ['nullable', 'string', 'max:500'],

            'fecha_prometida' => [
                'required',
                'date',
                'after_or_equal:' . $hoy->format('Y-m-d'),
                'before_or_equal:' . $hoy->copy()->addDays($anticipacionMaxima)->format('Y-m-d'),
            ],

            'fecha_realizada' => ['nullable', 'date', 'before_or_equal:today'],

            'duracion_minutos' => ['nullable', 'integer', 'min:15', 'max:' . (int) config('fotografia.agenda.duracion_maxima_minutos')],

            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function mensajes(): array
    {
        $anticipacionMaxima = (int) config('fotografia.agenda.anticipacion_maxima_dias');

        return parent::mensajes() + [
            'fecha_prometida.after_or_equal' => 'No se puede agendar una sesión en una fecha pasada.',
            'fecha_prometida.before_or_equal' => 'La fecha prometida no puede estar más de ' . $anticipacionMaxima . ' días adelante.',
            'fecha_realizada.before_or_equal' => 'La fecha en que se tookó la sesión no puede ser futura.',
        ];
    }
}