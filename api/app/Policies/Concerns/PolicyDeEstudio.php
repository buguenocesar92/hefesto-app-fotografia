<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\RolEstudio;
use App\Models\Usuario;
use App\Services\Permisos\CapacidadesDeUsuario;

/**
 * Base de todas las Policies.
 *
 * POR QUÉ "ES DEL MISMO ESTUDIO" VIENE PRIMERO
 * --------------------------------------------
 * Antes de preguntar qué puede hacer la persona hay que confirmar que el recurso
 * es suyo. Si se invirtiera el orden, alguien de otro estudio podría entrar a
 * preguntar "¿puedo ver esta sesión?" sobre una sesión que no es suya, y la
 * respuesta sería informativa aunque fuera un "no".
 *
 * EL RECURSO TRAE SU `tenant_id`
 * ------------------------------
 * Cada modelo de negocio usa el trait `PerteneceAlEstudio`, así que la comparación
 * es la misma en todas las Policies. La Row Level Security igual impide el acceso
 * en la base: esto es la segunda barrera, no la única.
 */
trait PolicyDeEstudio
{
    /**
     * ¿La persona y el recurso pertenecen al mismo estudio?
     *
     * @param  object  $recurso  un modelo con `tenant_id`
     */
    protected function esDelMismoEstudio(Usuario $usuario, object $recurso): bool
    {
        if (! property_exists($recurso, 'tenant_id')) {
            return false;
        }

        if ($recurso->tenant_id === null) {
            return false;
        }

        return (string) $recurso->tenant_id === (string) $usuario->tenant_id;
    }

    /**
     * ¿La persona tiene esta capacidad?
     *
     * Se pregunta por la capacidad y no por el rol: el rol dice cómo se organiza
     * el estudio, la capacidad dice qué hace falta. Ver
     * `App\Services\Permisos\CapacidadesDeUsuario`.
     */
    protected function puede(Usuario $usuario, string $capacidad): bool
    {
        return app(CapacidadesDeUsuario::class)->puede($usuario, $capacidad);
    }

    /** ¿La persona administra el estudio? */
    protected function esAdministrador(Usuario $usuario): bool
    {
        return $usuario->rol === RolEstudio::Administracion;
    }

    /**
     * ¿La cuenta está activa?
     *
     * Se comprueba en todas las Policies. Una cuenta desactivada en Authentik
     * sigue teniendo su fila acá, y sin esta comprobación entraría al estudio con
     * los permisos que tenía la última vez.
     */
    protected function estaActivo(Usuario $usuario): bool
    {
        return $usuario->activo === true && $usuario->tenant_id !== null;
    }
}