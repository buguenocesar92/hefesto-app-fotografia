<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `paquetes`: lo que el estudio ofrece.
 *
 * POR QUÉ NO HAY LÍNEAS ACÁ
 * -------------------------
 * Un paquete de "sesión de dos horas y cien fotos" son dos cosas que se
 * entregan por separado y en momentos distintos. Guardar eso como dos filas
 * declarada sería duplicar la definición del paquete en todas partes. La
 * definición vive en el catálogo del estudio y al contratar se COPIAN a
 * `lineas_contrato`, que es lo que queda congelado con lo que la persona firmó.
 *
 * `precio_referencia` NO ES LO QUE SE COBRA
 * ------------------------------------------
 * Es el punto de partida. El precio real vive en las líneas del contrato, y el
 * total en `contratos.precio_total`. Así, cambiar el precio de un paquete no
 * cambia lo que un cliente firmó hace tres años.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $descripcion
 * @property int $precio_referencia
 * @property bool $activo
 */
class Paquete extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'paquetes';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'descripcion',
        'precio_referencia',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_referencia' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class, 'paquete_id');
    }
}