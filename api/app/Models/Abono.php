<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetodoAbono;
use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `abonos`: plata que entró.
 *
 * POR QUÉ NO ESTÁ DENTRO DE `contratos`
 * -------------------------------------
 * Porque "se entregó" y "se pagó" son dos hechos y no se mezclan. Que se hayan
 * entregado veinte fotos no dice que se hayan pagado; que se haya pagado no dice
 * que se haya entregado. Un estudio que junta las dos cosas en una columna no
 * puede responder "¿qué falta entregar?" ni "¿quién me debe?".
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contrato_id
 * @property string|null $registrado_por
 * @property string $codigo
 * @property \Illuminate\Support\Carbon $fecha
 * @property int $monto
 * @property MetodoAbono $metodo
 * @property string|null $referencia
 * @property string|null $notas
 */
class Abono extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'abonos';

    protected $fillable = [
        'tenant_id',
        'contrato_id',
        'registrado_por',
        'codigo',
        'fecha',
        'monto',
        'metodo',
        'referencia',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'monto' => 'integer',
            'metodo' => MetodoAbono::class,
        ];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class, 'contrato_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por');
    }

    /** ¿Este abono deja un rastro que se pueda contrastar después? */
    public function dejaRastro(): bool
    {
        return $this->metodo instanceof MetodoAbono && $this->metodo->dejaRastro();
    }
}