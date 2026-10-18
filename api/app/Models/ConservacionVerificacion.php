<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResultadoVerificacion;
use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `conservacion_verificaciones`: la revisión, escrita.
 *
 * POR QUÉ HAY HISTORIAL Y NO SOLO UNA FECHA
 * ----------------------------------------
 * Sin historial no hay forma de demostrar que el estudio cuidó lo que se prometió
 * entregar. Con historial, un disco que empezó a fallar en enero y se declare en
 * junio muestra seis meses de revisiones con faltantes, que es la diferencia
 * entre una pérdida y una mala suerte.
 *
 * `no_se_pudo_verificar` NO ES UN FALLO
 * -------------------------------------
 * Cuando el equipo estaba en otra ciudad o el estudio estaba cerrado. Registrar
 * eso como fallo haría que el estudio se cansara de las alertas y dejara de
 * mirarlas, que es exactamente cómo se pierde un disco.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conservacion_id
 * @property string|null $usuario_id
 * @property \Illuminate\Support\Carbon $fecha
 * @property ResultadoVerificacion $resultado
 * @property int|null $archivos_revisados
 * @property int $archivos_faltantes
 * @property string|null $observaciones
 */
class ConservacionVerificacion extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'conservacion_verificaciones';

    protected $fillable = [
        'tenant_id',
        'conservacion_id',
        'usuario_id',
        'fecha',
        'resultado',
        'archivos_revisados',
        'archivos_faltantes',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'resultado' => ResultadoVerificacion::class,
            'archivos_revisados' => 'integer',
            'archivos_faltantes' => 'integer',
        ];
    }

    public function conservacion(): BelongsTo
    {
        return $this->belongsTo(Conservacion::class, 'conservacion_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /** ¿Se comprobó de verdad que los archivos estaban? */
    public function fueComprobada(): bool
    {
        return $this->resultado instanceof ResultadoVerificacion
            && $this->resultado->fueComprobada();
    }
}