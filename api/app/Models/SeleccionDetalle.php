<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `seleccion_detalles`: qué foto se eligió.
 *
 * POR QUÉ LLEVA `tenant_id` Y NO SÓLO DOS CLAVES FORÁNEAS
 * ---------------------------------------------------
 * Una tabla de unión sin `tenant_id` sería la puerta por la que se leería la
 * selección de otro estudio, y el comando `fotografia:verificar-aislamiento` la
 * marcaría como tabla sin protección. Llevarla es lo que hace que el patrón se
 * pueda aplicar sin excepciones.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $seleccion_id
 * @property string $foto_id
 * @property bool $elegida
 */
class SeleccionDetalle extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'seleccion_detalles';

    protected $fillable = [
        'tenant_id',
        'seleccion_id',
        'foto_id',
        'elegida',
    ];

    protected function casts(): array
    {
        return [
            'elegida' => 'boolean',
        ];
    }

    public function seleccion(): BelongsTo
    {
        return $this->belongsTo(Seleccion::class, 'seleccion_id');
    }

    public function foto(): BelongsTo
    {
        return $this->belongsTo(Foto::class, 'foto_id');
    }
}