<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RolEstudio;
use App\Models\Estudio;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Estudio::factory(),
            'nombre' => $this->faker->name(),
            'correo' => $this->faker->unique()->safeEmail(),
            'rol' => RolEstudio::Recepcion->value,
            // Por omisión NO se abren originales. El permiso se concede a mano,
            // y por eso las pruebas que lo necesitan lo piden explícitamente.
            'puede_ver_archivos' => false,
            'authentik_id' => null,
            'activo' => true,
        ];
    }

    public function administracion(): static
    {
        return $this->state(fn (): array => ['rol' => RolEstudio::Administracion->value]);
    }

    public function fotografia(): static
    {
        return $this->state(fn (): array => ['rol' => RolEstudio::Fotografo->value]);
    }

    /** Una cuenta que sí puede abrir originales. */
    public function conArchivos(): static
    {
        return $this->state(fn (): array => ['puede_ver_archivos' => true]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}