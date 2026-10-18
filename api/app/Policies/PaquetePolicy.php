<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RolEstudio;
use App\Models\Paquete;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeEstudio;

/**
 * Catálogo de paquetes.
 *
 * LEER ES DE TODOS
 * -----------------
 * La lista de paquetes la ve el estudio entero, hasta fotografía: es la carta.
 *
 * CAMBIARLA ES DE ADMINISTRACIÓN
 * ------------------------------
 * Un paquete nuevo es una decisión comercial. Y un paquete existente cambia
 * únicamente para los contratos que se abran después: los ya firmados tienen su
 * precio congelado en `contratos.precio_total` y sus líneas copiadas. Por eso
 * cambiar el catálogo no le hace nada a nadie de antes. Ver docs/06.
 */
class PaquetePolicy
{
    use PolicyDeEstudio;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario);
    }

    public function view(Usuario $usuario, Paquete $paquete): bool
    {
        return $this->viewAny($usuario) && $this->esDelMismoEstudio($usuario, $paquete);
    }

    public function create(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->esAdministrador($usuario);
    }

    public function update(Usuario $usuario, Paquete $paquete): bool
    {
        return $this->view($usuario, $paquete) && $this->esAdministrador($usuario);
    }

    public function delete(Usuario $usuario, Paquete $paquete): bool
    {
        // Desactivar, no borrar: un contrato anterior apunta a esta fila y su
        // historia importa aunque el paquete ya no se ofrezca.
        return $this->view($usuario, $paquete)
            && $this->usuarioAdministrador($usuario)
            && ! $paquete->contratos()->exists();
    }

    private function usuarioAdministrador(Usuario $usuario): bool
    {
        return $usuario->rol === RolEstudio::Administracion;
    }
}