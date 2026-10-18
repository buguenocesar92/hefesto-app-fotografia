<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EstadoContrato;
use App\Models\Contrato;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeEstudio;

/**
 * Un contrato con paquete.
 *
 * LEER ES DE TODOS, TOCAR ES DE UNOS
 * ----------------------------------
 * La pantalla de saldos es del estudio entero: saber quién debe es información de
 * todos. Lo que es de la administración es cambiar un contrato ya activo, anularlo
 * y cerrar cuentas viejas.
 *
 * ANULAR, NO BORRAR
 * -----------------
 * Un contrato con sesiones realizadas y fotos entregadas no se borra: se anula.
 * Es la diferencia entre un estudio que puede responder por sus trabajos de hace
 * tres años y uno que no puede. Ver docs/02.
 */
class ContratoPolicy
{
    use PolicyDeEstudio;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->puede($usuario, 'ver_contratos');
    }

    public function view(Usuario $usuario, Contrato $contrato): bool
    {
        return $this->viewAny($usuario) && $this->esDelMismoEstudio($usuario, $contrato);
    }

    /** Abrir un contrato nuevo. Lo hace recepción: es parte del atender. */
    public function create(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->puede($usuario, 'gestionar_contratos');
    }

    /**
     * Cambiar un contrato.
     *
     * Un contrato `activo` tiene sesiones con fecha y fotos entregadas. Cambiarle
     * las líneas o el precio a esa altura es reescribir la historia, y eso es de
     * administración y no de recepción.
     */
    public function update(Usuario $usuario, Contrato $contrato): bool
    {
        if (! $this->view($usuario, $contrato)) {
            return false;
        }

        return $contrato->estado === EstadoContrato::Borrador || $this->esAdministrador($usuario);
    }

    /** Registrar una entrega parcial. Lo hace cualquiera que gestiona contratos. */
    public function registrarEntrega(Usuario $usuario, Contrato $contrato): bool
    {
        return $this->view($usuario, $contrato) && $this->puede($usuario, 'gestionar_contratos');
    }

    public function delete(Usuario $usuario, Contrato $contrato): bool
    {
        // Borrar, no. Ver la nota del encabezado.
        return false;
    }

    public function anular(Usuario $usuario, Contrato $contrato): bool
    {
        return $this->view($usuario, $contrato) && $this->esAdministrador($usuario);
    }
}