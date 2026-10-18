<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sesion;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeEstudio;

/**
 * Una sesión de fotografía.
 *
 * LO QUE ES DE TODOS Y LO QUE ES DE UNO
 * -------------------------------------
 * Toda la agenda del estudio la ven las tres personas: es el turno de mañana. Lo
 * que es de uno es la marca de la sesión en `pendiente_seleccion` sin haber pasado
 * por selección, y la lista de sesiones a las que esa persona fotografió.
 *
 * DAR POR ENTREGADA ES MÁS EXIGENTE
 * ---------------------------------
 * Marcar entregada cambia lo que el estudio le puede decir a la persona, y una
 * vez marcado no se deshace fácil: es un hecho en la historia del trabajo. Por
 * eso no alcanza con que pueda agendar: hace falta la capacidad `dar_por_entregada`.
 * Y el permiso NO reemplaza la regla: aunque la tenga, sin selección confirmada
 * el sistema lo rechaza. Ver App\Services\Sesiones\GestorDeEstadoDeSesion.
 */
class SesionPolicy
{
    use PolicyDeEstudio;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->puede($usuario, 'ver_agenda');
    }

    public function view(Usuario $usuario, Sesion $sesion): bool
    {
        return $this->viewAny($usuario) && $this->esDelMismoEstudio($usuario, $sesion);
    }

    public function create(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->puede($usuario, 'gestionar_sesiones');
    }

    public function update(Usuario $usuario, Sesion $sesion): bool
    {
        return $this->view($usuario, $sesion) && $this->puede($usuario, 'gestionar_sesiones');
    }

    /** Marcar la sesión como entregada. Ver la nota del encabezado. */
    public function entregar(Usuario $usuario, Sesion $sesion): bool
    {
        return $this->view($usuario, $sesion) && $this->puede($usuario, 'dar_por_entregada');
    }

    /** Registrar o cambiar la selección de fotos. */
    public function seleccionar(Usuario $usuario, Sesion $sesion): bool
    {
        return $this->view($usuario, $sesion) && $this->puede($usuario, 'registrar_seleccion');
    }

    /**
     * Abrir un original de esta sesión.
     *
     * Es un permiso aparte y no se deduce del rol: la cuenta tiene que tenerlo
     * declarado en `usuarios.puede_ver_archivos`. Ver docs/07.
     */
    public function verArchivos(Usuario $usuario, Sesion $sesion): bool
    {
        return $this->view($usuario, $sesion) && $this->puede($usuario, 'ver_archivos');
    }

    public function delete(Usuario $usuario, Sesion $sesion): bool
    {
        // Borrar una sesión no se hace: se anula el contrato al que pertenece. Un
        // estudio que borra su historial de fechas prometidas no puede responder
        // por nadie. Ver docs/02.
        return false;
    }
}