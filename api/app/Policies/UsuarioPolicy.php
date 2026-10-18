<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RolEstudio;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeEstudio;

/**
 * Las cuentas del estudio.
 *
 * POR QUÉ UNA CUENTA NO PUEDE DESTRABARSE A SÍ MISMA
 * --------------------------------------------------
 * Una persona que puede dar de alta cuentas puede crear una con el rol de
 * administración y meterse adentro. Por eso `update` y `delete` exigen que el
 * objetivo sea otra cuenta.
 *
 * Y por qué la última cuenta de administración no se puede desactivar: si se
 * queda sin nadie que administre, no hay quién recupere el estudio. Es una
 * comprobación de una sola línea que evita un bloqueo operativo real. Ver
 * docs/07.
 */
class UsuarioPolicy
{
    use PolicyDeEstudio;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->esAdministrador($usuario);
    }

    public function view(Usuario $usuario, Usuario $recurso): bool
    {
        return $this->viewAny($usuario) && $this->esDelMismoEstudio($usuario, $recurso);
    }

    public function create(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->esAdministrador($usuario);
    }

    public function update(Usuario $usuario, Usuario $recurso): bool
    {
        if (! $this->view($usuario, $recurso)) {
            return false;
        }

        // Nadie se destraba a sí mismo.
        return (string) $usuario->id !== (string) $recurso->id;
    }

    public function delete(Usuario $usuario, Usuario $recurso): bool
    {
        if (! $this->update($usuario, $recurso)) {
            return false;
        }

        return $this->quedanOtrosAdministradores($recurso);
    }

    /**
     * ¿Queda al menos una cuenta de administración activa?
     *
     * La cuenta que se está por desactivar no cuenta: si fuera la única, el
     * estudio quedaría sin administradores y no habría quién recupere el
     * acceso.
     */
    private function quedanOtrosAdministradores(Usuario $recurso): bool
    {
        return Usuario::query()
            ->where('tenant_id', $recurso->tenant_id)
            ->where('id', '!=', $recurso->id)
            ->where('rol', RolEstudio::Administracion->value)
            ->where('activo', true)
            ->exists();
    }
}