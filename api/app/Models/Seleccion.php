<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoSeleccion;
use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `selecciones`: qué eligió la persona.
 *
 * POR QUÉ UNA SESIÓN TIENE UNA SOLA SELECCIÓN VIVA
 * -----------------------------------------------
 * Volver a elegir no crea una segunda fila: actualiza la que hay. Si se creara
 * una nueva, "cuándo confirmó la persona" quedaría repartido entre varias filas y
 * no se podría responder. La UNIQUE de `sesion_id` en la migración obliga a que
 * sea así.
 *
 * `confirmada` ES LA QUE PERMITE ENTREGAR
 * ---------------------------------------
 * `pendiente` es lo normal y no es un error: la sesión se tomó y nadie ha
 * abierto la galería todavía. Ver docs/03.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sesion_id
 * @property string $cliente_id
 * @property EstadoSeleccion $estado
 * @property int $cantidad_seleccionada
 * @property \Illuminate\Support\Carbon|null $fecha_confirmacion
 * @property string|null $notas
 */
class Seleccion extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'selecciones';

    protected $fillable = [
        'tenant_id',
        'sesion_id',
        'cliente_id',
        'estado',
        'cantidad_seleccionada',
        'fecha_confirmacion',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoSeleccion::class,
            'cantidad_seleccionada' => 'integer',
            'fecha_confirmacion' => 'datetime',
        ];
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(Sesion::class, 'sesion_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(SeleccionDetalle::class, 'seleccion_id');
    }

    /** Las fotos que se eligieron de verdad. */
    public function fotosElegidas(): HasMany
    {
        return $this->detalles()->where('elegida', true)->with('foto');
    }

    /** ¿Hay una decisión firme de la persona? */
    public function estaConfirmada(): bool
    {
        return $this->estado === EstadoSeleccion::Confirmada
            && $this->fecha_confirmacion !== null;
    }

    /**
     * Cuántas fotos hay marcadas como elegidas de verdad.
     *
     * Se cuenta sobre los detalles y no se lee de `cantidad_seleccionada`: el
     * número guardado es una copia, y la fuente es el detalle. Si la copia se
     * desincroniza, esta cuenta muestra la verdad.
     */
    public function contarElegidas(): int
    {
        return $this->detalles()->where('elegida', true)->count();
    }
}