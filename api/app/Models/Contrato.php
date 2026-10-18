<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoContrato;
use App\Models\Concerns\PerteneceAlEstudio;
use App\Services\Cobranza\DesgloseDeSaldo;
use App\Services\Cobranza\ServicioDeSaldo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `contratos`: un paquete ya contratado.
 *
 * `precio_total` SE CONGELA EN LA FIRMA
 * -------------------------------------
 * Cambiar el precio del paquete después no puede cambiar lo que este cliente
 * firmó. Por eso el total se copia acá y las líneas se copian con su precio, y
 * no se leen del paquete nunca más.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $cliente_id
 * @property string|null $paquete_id
 * @property string $codigo
 * @property \Illuminate\Support\Carbon $fecha_contrato
 * @property int $precio_total
 * @property EstadoContrato $estado
 * @property int|null $dias_promesa_entrega
 * @property \Illuminate\Support\Carbon|null $fecha_cierre
 * @property string|null $notas
 */
class Contrato extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'contratos';

    protected $fillable = [
        'tenant_id',
        'cliente_id',
        'paquete_id',
        'codigo',
        'fecha_contrato',
        'precio_total',
        'estado',
        'dias_promesa_entrega',
        'fecha_cierre',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha_contrato' => 'date',
            'fecha_cierre' => 'datetime',
            'precio_total' => 'integer',
            'dias_promesa_entrega' => 'integer',
            'estado' => EstadoContrato::class,
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function paquete(): BelongsTo
    {
        return $this->belongsTo(Paquete::class, 'paquete_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(LineaContrato::class, 'contrato_id');
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(Sesion::class, 'contrato_id');
    }

    public function abonos(): HasMany
    {
        return $this->hasMany(Abono::class, 'contrato_id');
    }

    public function pedidosLaboratorio(): HasMany
    {
        return $this->hasMany(PedidoLaboratorio::class, 'contrato_id');
    }

    /**
     * El desglose completo de lo que se entregó y lo que falta.
     *
     * NO devuelve un número: devuelve el DESGLOSE. La pregunta que hace el
     * estudio es "¿qué le entrego, qué le falta y cuánto me debe?", y una sola
     * cifra no la contesta. Ver App\Services\Cobranza\CalculadoraDeSaldo.
     */
    public function saldo(): DesgloseDeSaldo
    {
        return app(ServicioDeSaldo::class)->para($this);
    }
}