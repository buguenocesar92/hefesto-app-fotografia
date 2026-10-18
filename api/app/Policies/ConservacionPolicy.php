<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conservacion;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeEstudio;

/**
 * Un medio con originales.
 *
 * QUIÉN VE LA LISTA Y QUIÉN LA MODIFICA
 * -------------------------------------
 * La lista la ven las tres personas: saber en qué disco están las fotos de cada
 * sesión es lo primero que hay que poder contestar cuando alguien pregunta por
 * una foto. Lo que es de la administración es cambiar el estado y registrar
 * revisiones, porque un cambio de estado es una afirmación sobre la custodia.
 *
 * POR QUÉ EL ESTADO NO LO CAMBIA CUALQUIERA
 * ----------------------------------------
 * Poner `destruida` es decir que los originales de una familia se perdieron.
 * Poner `activa` es decir que se comprobó que están. Las dos cosas las dice una
 * persona, no un formulario: por eso el estado se cambia desde administración y
 * la verificación deja historial.
 *
 * Ver docs/08.
 */
class ConservacionPolicy
{
    use PolicyDeEstudio;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->puede($usuario, 'ver_agenda');
    }

    public function view(Usuario $usuario, Conservacion $conservacion): bool
    {
        return $this->viewAny($usuario) && $this->esDelMismoEstudio($usuario, $conservacion);
    }

    public function create(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->esAdministrador($usuario);
    }

    /** Cambiar el estado del medio. Es una afirmación sobre la custodia. */
    public function update(Usuario $usuario, Conservacion $conservacion): bool
    {
        return $this->view($usuario, $conservacion) && $this->esAdministrador($usuario);
    }

    /**
     * Registrar una revisión.
     *
     * No es de administración: es la persona que fue a mirar. Un estudio donde
     * solo el dueño puede verificar un disco es un estudio donde el disco se
     * verifica cuando el dueño se acuerda.
     */
    public function verificar(Usuario $usuario, Conservacion $conservacion): bool
    {
        return $this->view($usuario, $conservacion);
    }

    public function delete(Usuario $usuario, Conservacion $conservacion): bool
    {
        // Borrar, no. Un medio con sesiones entregadas se marca `destruida` y se
        // deja de usar. Ver la nota del encabezado.
        return false;
    }
}