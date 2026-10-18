<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoSesion;
use App\Models\Cliente;
use App\Models\Sesion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sesion>
 */
class SesionFactory extends Factory
{
    protected $model = Sesion::class;

    /**
     * Por omisión la sesión está `agendada`, porque es el estado desde el que se
     * parte casi siempre: una fecha prometida y nada más.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'contrato_id' => null,
            'cliente_id' => Cliente::factory(),
            'fotografo_id' => null,
            'tipo' => TipoSesion::Cumpleanos->value,
            'titulo' => 'Cumpleaños de prueba',
            'lugar' => 'Salón de pruebas',
            'fecha_prometida' => now()->addDays(30)->toDateString(),
            'fecha_realizada' => null,
            'estado' => 'agendada',
            'duracion_minutos' => 120,
            'fecha_prometida_entrega' => null,
            'conservacion_id' => null,
            'notas' => null,
        ];
    }

    public function realizada(): static
    {
        return $this->state(fn (): array => [
            'estado' => 'realizada',
            'fecha_realizada' => now()->subDays(2)->toDateString(),
        ]);
    }

    public function pendienteDeSeleccion(): static
    {
        return $this->state(fn (): array => [
            'estado' => 'pendiente_seleccion',
            'fecha_realizada' => now()->subDays(2)->toDateString(),
            'fecha_prometida_entrega' => now()->addDays(10)->toDateString(),
        ]);
    }

    public function entregada(): static
    {
        return $this->state(fn (): array => [
            'estado' => 'entregada',
            'fecha_realizada' => now()->subDays(30)->toDateString(),
            'fecha_prometida_entrega' => now()->subDays(10)->toDateString(),
        ]);
    }
}