<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPedidoLaboratorio;
use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `pedidos_laboratorio`: la impresión como SUBCONTRATO.
 *
 * DOS FECHAS, DOS COMPROMISOS
 * ---------------------------
 *   - `fecha_prometida_recepcion`: lo que el estudio le dijo al CLIENTE.
 *   - `dias_plazo_laboratorio`: lo que el estudio le pidió al LABORATORIO.
 *
 * Son dos promesas distintas, con dos consecuencias distintas, y confundirlas es
 * como un estudio termina diciéndole a la gente que el laboratorio es culpable de
 * una fecha que el estudio mismo se $.
 *
 * `estado` ADMITE FALTANTES A PROPÓSITO
 * ------------------------------------
 * `recibido_con_fallos` existe porque es lo que pasa siempre: el laboratorio
 * devuelve cuarenta y nueve de cincuenta y avisa. Registrar eso como recibido
 * sería mentir sobre lo que se le entregó a la persona, y el faltante se
 * descubriría después, cuando la familia viene a buscar.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $laboratorio_id
 * @property string|null $sesion_id
 * @property string|null $contrato_id
 * @property string $codigo
 * @property \Illuminate\Support\Carbon|null $fecha_envio
 * @property \Illuminate\Support\Carbon|null $fecha_prometida_recepcion
 * @property int|null $dias_plazo_laboratorio
 * @property \Illuminate\Support\Carbon|null $fecha_recepcion_real
 * @property EstadoPedidoLaboratorio $estado
 * @property int $costo_total
 * @property string|null $observaciones
 */
class PedidoLaboratorio extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'pedidos_laboratorio';

    protected $fillable = [
        'tenant_id',
        'laboratorio_id',
        'sesion_id',
        'contrato_id',
        'codigo',
        'fecha_envio',
        'fecha_prometida_recepcion',
        'dias_plazo_laboratorio',
        'fecha_recepcion_real',
        'estado',
        'costo_total',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_envio' => 'date',
            'fecha_prometida_recepcion' => 'date',
            'fecha_recepcion_real' => 'date',
            'dias_plazo_laboratorio' => 'integer',
            'costo_total' => 'integer',
            'estado' => EstadoPedidoLaboratorio::class,
        ];
    }

    public function laboratorio(): BelongsTo
    {
        return $this->belongsTo(Laboratorio::class, 'laboratorio_id');
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(Sesion::class, 'sesion_id');
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class, 'contrato_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PedidoLaboratorioItem::class, 'pedido_id');
    }

    /** Cuántas unidades se pidieron en total, en cualquier formato. */
    public function cantidadPedida(): int
    {
        return (int) $this->items()->sum('cantidad_pedida');
    }

    /** Cuántas unidades volvieron, en cualquier formato. */
    public function cantidadRecibida(): int
    {
        return (int) $this->items()->sum('cantidad_recibida');
    }

    /**
     * ¿La fecha prometida al cliente ya pasó y el trabajo no volvió?
     *
     * Es la pregunta que hay que mirar todas las mañanas, y por eso la pantalla
     * de vencimientos la ordena así.
     */
    public function estaVencido(\DateTimeInterface $hoy = null): bool
    {
        if ($this->estado->fueRecibido()) {
            return false;
        }

        if ($this->fecha_prometida_recepcion === null) {
            return false;
        }

        $hoy = $hoy ?? today();

        return $this->fecha_prometida_recepcion->startOfDay()->lessThan(
            \Illuminate\Support\Carbon::instance($hoy)->startOfDay()
        );
    }
}