<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RolEstudio;
use App\Models\Usuario;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una cuenta del estudio.
 *
 * `puede_ver_archivos` SÍ SE PIDE ACÁ
 * -----------------------------------
 * Es un permiso sobre los originales de las fotos de otras personas, y se concede
 * por cuenta concreta. El rol no lo implica: una persona puede ser fotografía y
 * no estar autorizada a abrir las sesiones de otros. Ver docs/07.
 */
class GuardarUsuarioRequest extends PeticionDeFotografia
{
    /** @return array<string, mixed> */
    public function reglas(): array
    {
        $usuario = $this->usuario();
        $estudioId = $usuario !== null ? (string) $usuario->tenant_id : null;

        return [
            'nombre' => ['required', 'string', 'max:160'],

            // La unicidad está en la base (`unique` sobre `tenant_id` + `correo`),
            // pero comprobarla acá devuelve un mensaje que se puede leer, en vez
            // de un error de PostgreSQL que no.
            'correo' => [
                'required',
                'email',
                'max:160',
                Rule::unique('usuarios', 'correo')
                    ->where(static fn ($consulta) => $consulta->where('tenant_id', $estudioId))
                    ->ignore($this->route('usuario')),
            ],

            'rol' => ['required', Rule::enum(RolEstudio::class)],
            'puede_ver_archivos' => ['sometimes', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    private function usuario(): ?Usuario
    {
        $usuario = $this->user();

        return $usuario instanceof Usuario ? $usuario : null;
    }
}