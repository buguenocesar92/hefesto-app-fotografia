<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cliente;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeEstudio;

/**
 * Ficha de la persona de la que son las fotos.
 *
 * UNA REGLA QUE PARECE DISPARATADA Y NO LO ES
 * --------------------------------------------
 * Una ficha de cliente solo tiene nombre, RUT, teléfono y correo. Y sin embargo
 * no la puede ver cualquiera del estudio: quien puede ver fotos de los hijos de
 * una familia es quien la persona autorizó para eso, y en un estudio chico eso no
 * es todo el mundo.
 *
 * Por eso `ver` exige la capacidad `gestionar_contratos` y no una capacidad de
 * "ver clientes". Ver docs/03 y docs/07.
 */
class ClientePolicy
{
    use PolicyDeEstudio;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->puede($usuario, 'gestionar_contratos');
    }

    public function view(Usuario $usuario, Cliente $cliente): bool
    {
        return $this->viewAny($usuario) && $this->esDelMismoEstudio($usuario, $cliente);
    }

    public function create(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->puede($usuario, 'gestionar_contratos');
    }

    public function update(Usuario $usuario, Cliente $cliente): bool
    {
        return $this->view($usuario, $cliente);
    }

    /**
     * DESACTIVAR, NO BORRAR
     * ----------------------
     * Una persona que contratar en 2026 puede volver en 2029, y para entonces su
     * ficha tiene que seguir ahí con sus sesiones. Por eso `destroy` desactiva y
     * no borra. Ver docs/02.
     */
    public function delete(Usuario $usuario, Cliente $cliente): bool
    {
        return $this->view($usuario, $cliente) && $this->esAdministrador($usuario);
    }
}