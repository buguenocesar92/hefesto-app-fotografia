<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `fotos`: los originales de una sesión.
 *
 * `ruta_relativa` NO ES UNA RUTA DE SISTEMA DE ARCHIVOS
 * ---------------------------------------------------
 * Es un nombre relativo DENTRO del medio declarado en
 * `sesiones.conservacion_id`. Sin la conservación, la ruta no significa nada:
 * por eso el sistema no guarda aquí una ruta absoluta, que seguiría siendo válida
 * en la pantalla después de que el disco se haya movido.
 *
 * `huella_sha256` ES LO QUE PERMITE AUDITAR LA CUSTODIA
 * ---------------------------------------------------
 * Es la misma suma que va en el manifiesto de los respaldos. Con ella, dos años
 * después se puede probar que el archivo entregado es el mismo que se escaneó.
 * Ver docs/07 y docs/08.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sesion_id
 * @property string $nombre_archivo
 * @property string|null $extension
 * @property int|null $bytes
 * @property string|null $huella_sha256
 * @property string|null $ruta_relativa
 */
class Foto extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'fotos';

    protected $fillable = [
        'tenant_id',
        'sesion_id',
        'nombre_archivo',
        'extension',
        'bytes',
        'huella_sha256',
        'ruta_relativa',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
        ];
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(Sesion::class, 'sesion_id');
    }

    public function detallesDeSeleccion(): HasMany
    {
        return $this->hasMany(SeleccionDetalle::class, 'foto_id');
    }

    /** ¿Esta foto se eligió en alguna selección? */
    public function fueElegida(): bool
    {
        return $this->detallesDeSeleccion()->where('elegida', true)->exists();
    }
}