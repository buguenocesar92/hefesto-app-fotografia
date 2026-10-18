<?php

declare(strict_types=1);

namespace App\Services\Permisos;

use App\Enums\RolEstudio;
use App\Models\Usuario;

/**
 * Traduce el rol a capacidades.
 *
 * POR QUÉ HAY CAPACIDADES Y NO SOLO ROLES
 * ---------------------------------------
 * El rol dice cómo se organiza el estudio; la capacidad dice qué necesita hacer
 * la persona. Mañana el rol puede cambiar sin que cambie lo que se necesita, y
 * esta clase es el único lugar que hay que tocar.
 *
 * `puede_ver_archivos` NO ESTÁ EN EL ROL A PROPÓSITO
 * -------------------------------------------------
 * Abrir los originales de las fotos de una familia es un permiso aparte, y no se
 * deduce de que alguien sea fotografía: puede haber una persona que toma fotos
 * y que no debería abrir los archivos de las sesiones de otros. El permiso se
 * concede por cuenta, en `usuarios.puede_ver_archivos`. Ver docs/07.
 *
 * LO QUE ESTO NO ES
 * -----------------
 * Ocultar un botón. La interfaz usa estas capacidades SOLO para no mostrar lo que
 * no se puede hacer. Ocultar un botón no impide que alguien llame a la API
 * directamente, y por eso **no hay ningún Gate global**: cada ruta vuelve a
 * preguntar a su `Policy`. Ver docs/03.
 */
class CapacidadesDeUsuario
{
    /**
     * @return array<string, bool>
     */
    public function de(Usuario $usuario): array
    {
        $rol = $usuario->rol instanceof RolEstudio ? $usuario->rol : null;

        $activo = $usuario->activo === true;

        return [
            // Agenda: qué puede ver del calendario del estudio.
            'ver_agenda' => $activo,

            // Contratos y paquetes.
            'ver_contratos' => $activo,
            'gestionar_contratos' => $activo && $rol !== null,
            'administrar_paquetes' => $this->es($rol, RolEstudio::Administracion),

            // Sesiones y selección.
            'gestionar_sesiones' => $activo,
            'registrar_seleccion' => $activo && $rol !== null,
            'dar_por_entregada' => $rol !== null,

            // Impresión por subcontrato.
            'gestionar_pedidos_laboratorio' => $activo,
            'administrar_laboratorios' => $this->es($rol, RolEstudio::Administracion),

            // Caja.
            'registrar_abonos' => $activo,
            'ver_saldos' => $activo,
            'anular_abonos' => $this->es($rol, RolEstudio::Administracion),

            // Los originales. Ver la nota del encabezado.
            'ver_archivos' => $activo && $usuario->puede_ver_archivos === true,

            // Configuración y bitácora.
            'administra_fotografia' => $this->es($rol, RolEstudio::Administracion),
            'ver_auditoria' => $this->es($rol, RolEstudio::Administracion),
        ];
    }

    /** ¿La cuenta tiene esta capacidad? */
    public function puede(Usuario $usuario, string $capacidad): bool
    {
        return ($this->de($usuario)[$capacidad] ?? false) === true;
    }

    private function es(?RolEstudio $rol, RolEstudio $esperado): bool
    {
        return $rol === $esperado;
    }
}