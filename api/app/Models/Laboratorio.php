<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `laboratorios`: a quién le encarga el estudio la impresión.
 *
 * POR QUÉ ESTO ES UNA TABLA Y NO UN TEXTO EN EL PEDIDO
 * ---------------------------------------------------
 * El estudio ENCARGA la impresión a un laboratorio externo, y eso se repite: el
 * mismo laboratorio aparece en cientos de pedidos. Guardar el nombre y el
 * teléfono en cada pedido sería copiar los mismos datos y no poder ver, de un
 * vistazo, con quién trabaja el estudio y cómo le fue con cada uno.
 *
 * El PRECIO lo negocia el estudio con cada laboratorio, por eso no hay una lista
 * de precios en el software: cada estudio declara los suyos en el catálogo y en
 * cada pedido. Ver config/fotografia.php y docs/05.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nombre
 * @property string|null $contacto
 * @property string|null $telefono
 * @property string|null $correo
 * @property bool $activo
 */
class Laboratorio extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'laboratorios';

    protected $fillable = [
        'tenant_id',
        'nombre',
        'contacto',
        'telefono',
        'correo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(PedidoLaboratorio::class, 'laboratorio_id');
    }
}