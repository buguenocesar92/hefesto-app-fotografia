<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoLineaContrato;
use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `lineas_contrato`: una cosa prometida, y cuánto de ella se entregó.
 *
 * POR QUÉ `cantidad_entregada` VIVE ACÁ
 * -------------------------------------
 * Es lo que permite responder "cuánto se entregó y cuánto falta" en cualquier
 * momento, sin recalcular nada y sin que el dato dependa de otra tabla. Si el
 * total entregado fuera una columna del contrato, habría que recalcularlo en cada
 * escritura y no se podría decir DE QUÉ se entregó.
 *
 * UNA ENTREGA PARCIAL NO ES UN ESTADO
 * ----------------------------------
 * `cantidad_entregada` entre cero y `cantidad_pactada` es lo normal a mitad de un
 * matrimonio. El contrato sigue en `activo`. Lo que cierra el contrato es que
 * TODAS las líneas queden completas, y eso lo decide
 * App\Services\Contratos\RegistradorDeEntrega.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contrato_id
 * @property TipoLineaContrato $tipo
 * @property string $concepto
 * @property int|null $unidad_minutos
 * @property int $cantidad_pactada
 * @property int $cantidad_entregada
 * @property int $precio_unitario
 * @property string|null $detalle
 */
class LineaContrato extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'lineas_contrato';

    protected $fillable = [
        'tenant_id',
        'contrato_id',
        'tipo',
        'concepto',
        'unidad_minutos',
        'cantidad_pactada',
        'cantidad_entregada',
        'precio_unitario',
        'detalle',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoLineaContrato::class,
            'unidad_minutos' => 'integer',
            'cantidad_pactada' => 'integer',
            'cantidad_entregada' => 'integer',
            'precio_unitario' => 'integer',
        ];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class, 'contrato_id');
    }

    /** Lo que esta línea promete, en pesos. */
    public function valorPactado(): int
    {
        return $this->cantidad_pactada * $this->precio_unitario;
    }

    /** Lo que esta línea ha entregado, en pesos. */
    public function valorEntregado(): int
    {
        return $this->cantidad_entregada * $this->precio_unitario;
    }

    /** Cuántas unidades faltan, en cualquier unidad. */
    public function cantidadPendiente(): int
    {
        return max(0, $this->cantidad_pactada - $this->cantidad_entregada);
    }

    /** Cuánto vale lo que falta, en pesos. */
    public function valorPendiente(): int
    {
        return $this->cantidadPendiente() * $this->precio_unitario;
    }

    /** ¿Esta línea está completa? */
    public function estaCompleta(): bool
    {
        return $this->cantidadPendiente() === 0;
    }

    /**
     * ¿Se entregó de más?
     *
     * No es un error de cálculo: una persona puede elegir más fotos que las que
     * el paquete prometía. Lo que no puede pasar es que el sistema lo guarde en
     * silencio. Por eso la pregunta existe, y por eso la entrega se valida antes
     * de escribirse.
     */
    public function tieneExceso(): bool
    {
        return $this->cantidad_entregada > $this->cantidad_pactada;
    }
}