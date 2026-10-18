<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccionAccesoArchivo;
use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `accesos_archivo`: quién abrió qué original, y si se le dejó.
 *
 * LA BITÁCORA ES EL MOTIVO DE ESTA TABLA
 * ---------------------------------------
 * Los originales de las fotos de una familia son datos personales, y lo son más
 * todavía cuando la persona fotografiada es menor de edad. Por eso cada apertura
 * queda anotada, PERMITIDA O DENEGADA, con su fecha.
 *
 * POR QUÉ SE ANOTA TAMBIÉN LA DENEGADA
 * -----------------------------------
 * Porque el intento denegado es justo el registro que hay que poder revisar. Si
 * se anotara solo lo que se permitió, un intento fallido no dejaría rastro.
 *
 * Y por eso la escritura va por la conexión de auditoría: la petición que se
 * deniega termina revertida, y con la misma conexión se perdería el registro.
 * Ver App\Services\Tenancy\AuditorDeAcceso.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $usuario_id
 * @property string|null $sesion_id
 * @property string|null $conservacion_id
 * @property AccionAccesoArchivo $accion
 * @property bool $permitido
 * @property string|null $ip
 * @property \Illuminate\Support\Carbon $fecha
 * @property string|null $detalle
 */
class AccesoArchivo extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'accesos_archivo';

    protected $fillable = [
        'tenant_id',
        'usuario_id',
        'sesion_id',
        'conservacion_id',
        'accion',
        'permitido',
        'ip',
        'fecha',
        'detalle',
    ];

    protected function casts(): array
    {
        return [
            'accion' => AccionAccesoArchivo::class,
            'permitido' => 'boolean',
            'fecha' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(Sesion::class, 'sesion_id');
    }

    public function conservacion(): BelongsTo
    {
        return $this->belongsTo(Conservacion::class, 'conservacion_id');
    }
}