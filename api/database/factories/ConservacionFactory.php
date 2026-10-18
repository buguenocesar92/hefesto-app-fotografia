<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoConservacion;
use App\Models\Conservacion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Conservacion>
 */
class ConservacionFactory extends Factory
{
    protected $model = Conservacion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'nombre' => 'Disco de archivo',
            'tipo_medio' => 'disco_local',
            'ubicacion' => '/mnt/archivo',
            'estado' => EstadoConservacion::Activa->value,
            'ultima_verificacion_en' => now()->subDays(5),
            'bytes_declarados' => null,
            'sesiones_asignadas' => 0,
            'notas' => null,
        ];
    }

    public function desconectada(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoConservacion::Desconectada->value]);
    }

    public function danada(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoConservacion::Danada->value]);
    }

    /** Un medio del que no hay noticias: nunca se verificó. */
    public function sinVerificar(): static
    {
        return $this->state(fn (): array => ['ultima_verificacion_en' => null]);
    }
}