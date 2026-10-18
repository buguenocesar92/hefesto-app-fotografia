<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Estudio;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Estudio>
 */
class EstudioFactory extends Factory
{
    protected $model = Estudio::class;

    /**
     * `estudios` NO tiene Row Level Security a propósito: se consulta antes de que
     * exista `app.tenant_id`. Por eso se puede crear sin contexto.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = $this->faker->company() . ' Fotografía';

        return [
            'id' => (string) Str::uuid(),
            'slug' => 'estudio-' . Str::lower(Str::random(8)),
            'nombre' => $nombre,
            'nombre_corto' => Str::limit($nombre, 64, ''),
            'direccion' => $this->faker->address(),
            'telefono' => '+56 9 0000 0000',
            'correo' => 'contacto@ejemplo.invalido',
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}