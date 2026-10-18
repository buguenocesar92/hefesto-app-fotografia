<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Abono;
use App\Models\Contrato;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeEstudio;

/**
 * Caja: saldos y abonos.
 *
 * CUESTIÓN DE ORDEN, NO DE PERMISO
 * -------------------------------
 * Quien atiende necesita poder registrar un abono: si la persona paga en el
 * mostrador y nadie lo puede anotar, el abono se anota en un cuaderno. Por eso
 * `registrar` es de recepción.
 *
 * Lo que NO es de recepción es DESHACER. Un abono mal cargado se corrige con otro
 * abono que lo anule, y eso lo hace la administración, porque deshacer una entrada
 * de caja es un acto de administración, no de atención.
 *
 * Ver la pantalla de saldos: leerla es de todos. Saber quién debe es
 * información de todo el estudio. Ver docs/03.
 */
class AbonoPolicy
{
    use PolicyDeEstudio;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->puede($usuario, 'ver_saldos');
    }

    public function view(Usuario $usuario, Abono $abono): bool
    {
        return $this->viewAny($usuario) && $this->esDelMismoEstudio($usuario, $abono);
    }

    /** Registrar un abono. Lo hace quien atiende. */
    public function create(Usuario $usuario, Contrato $contrato): bool
    {
        return $this->estaActivo($usuario)
            && $this->puede($usuario, 'registrar_abonos')
            && $this->esDelMismoEstudio($usuario, $contrato);
    }

    /** Anular o corregir un abono. Es de administración. Ver la nota del encabezado. */
    public function anular(Usuario $usuario, Abono $abono): bool
    {
        return $this->view($usuario, $abono) && $this->puede($usuario, 'anular_abonos');
    }

    public function delete(Usuario $usuario, Abono $abono): bool
    {
        // Borrar, no. Un abono se anula con otro abono que lo deja en cero. Ver
        // la nota del encabezado.
        return false;
    }
}