<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Laboratorio;
use App\Models\PedidoLaboratorio;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeEstudio;

/**
 * Laboratorios y pedidos de impresión.
 *
 * EL LABORATORIO Y EL PEDIDO NO TIENEN EL MISMO NIVEL
 * -------------------------------------------------
 * La lista de laboratorios la ven las tres personas, y también la de pedidos: en
 * un estudio chico quien atiende es quien llama al laboratorio. Lo que es de la
 * administración es dar de alta un laboratorio nuevo, porque ahí va el teléfono
 * de un proveedor y la decisión de trabajar con él.
 *
 * `seleccion` EN UN ÍTEM ES LA QUE IMPORTA
 * ----------------------------------------
 * Un ítem de impresión dice de qué selección salen las fotos. Si el servicio la
 * cuida, nadie puede encargar al laboratorio las fotos de una galería que nadie
 * confirmó. Ver docs/02.
 */
class PedidoLaboratorioPolicy
{
    use PolicyDeEstudio;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->puede($usuario, 'gestionar_pedidos_laboratorio');
    }

    public function view(Usuario $usuario, PedidoLaboratorio $pedido): bool
    {
        return $this->viewAny($usuario) && $this->esDelMismoEstudio($usuario, $pedido);
    }

    public function create(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->puede($usuario, 'gestionar_pedidos_laboratorio');
    }

    /**
     * Cambiar el contenido de un pedido.
     *
     * Solo mientras está en `borrador`. Uno enviado es un trabajo que OTRO está
     * haciendo, y cambiar la cantidad produce una factura que no cuadra con lo
     * entregado. Ver App\Services\Laboratorio\SeguimientoDePedidos.
     */
    public function update(Usuario $usuario, PedidoLaboratorio $pedido): bool
    {
        return $this->view($usuario, $pedido)
            && $this->puede($usuario, 'gestionar_pedidos_laboratorio');
    }

    /** Marcar el pedido como enviado. */
    public function enviar(Usuario $usuario, PedidoLaboratorio $pedido): bool
    {
        return $this->update($usuario, $pedido);
    }

    /** Registrar lo que volvió del laboratorio. */
    public function recibir(Usuario $usuario, PedidoLaboratorio $pedido): bool
    {
        return $this->view($usuario, $pedido)
            && $this->puede($usuario, 'gestionar_pedidos_laboratorio');
    }

    /** Dar de alta o cambiar un laboratorio. */
    public function administrarLaboratorio(Usuario $usuario): bool
    {
        return $this->estaActivo($usuario) && $this->puede($usuario, 'administrar_laboratorios');
    }

    public function verLaboratorio(Usuario $usuario, Laboratorio $laboratorio): bool
    {
        return $this->estaActivo($usuario)
            && $this->puede($usuario, 'gestionar_pedidos_laboratorio')
            && $this->esDelMismoEstudio($usuario, $laboratorio);
    }
}