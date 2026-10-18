<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoLineaContrato;
use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `pedido_laboratorio_items`: qué se encargó y cuánto volvió.
 *
 * POR QUÉ `cantidad_recibida` ESTÁ ACÁ Y NO EN EL PEDIDO
 * ---------------------------------------------------
 * Un pedido de cincuenta impresiones puede volver con cuarenta y nueve. Si el
 * total recibido fuera una columna del pedido, el faltante se perdería al
 * promediar y el estudio se enteraría después, cuando la familia venga a
 * buscar. Acá el faltante es una fila que se ve.
 *
 * `formato` ES TEXTO Y NO UNA LISTA DEL SOFTWARE
 * ---------------------------------------------
 * Cada laboratorio ofrece formatos distintos y los precios cambian por
 * temporada. El estudio declara los suyos al configurar la instalación; el
 * software no impone una lista que sería falsa en cuatro de cada cinco
 * laboratorios. Ver config/fotografia.php y docs/05.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $pedido_id
 * @property string|null $seleccion_id
 * @property TipoLineaContrato $tipo
 * @property string $formato
 * @property int $cantidad_pedida
 * @property int $cantidad_recibida
 * @property int $precio_unitario
 * @property string|null $detalle
 */
class PedidoLaboratorioItem extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'pedido_laboratorio_items';

    protected $fillable = [
        'tenant_id',
        'pedido_id',
        'seleccion_id',
        'tipo',
        'formato',
        'cantidad_pedida',
        'cantidad_recibida',
        'precio_unitario',
        'detalle',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoLineaContrato::class,
            'cantidad_pedida' => 'integer',
            'cantidad_recibida' => 'integer',
            'precio_unitario' => 'integer',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoLaboratorio::class, 'pedido_id');
    }

    public function seleccion(): BelongsTo
    {
        return $this->belongsTo(Seleccion::class, 'seleccion_id');
    }

    /** Cuántas unidades no volvieron del laboratorio. */
    public function cantidadFaltante(): int
    {
        return max(0, $this->cantidad_pedida - $this->cantidad_recibida);
    }

    /** ¿Este ítem volvió completo? */
    public function estaCompleto(): bool
    {
        return $this->cantidadFaltante() === 0;
    }
}