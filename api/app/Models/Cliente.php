<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `clientes`: de quién son las fotos.
 *
 * LA TABLA DE DATOS PERSONALES MÁS DELICADA DEL SISTEMA
 * -----------------------------------------------------
 * Un nombre, un RUT, un teléfono y un correo, de una persona que además puede
 * tener fotos de sus hijos guardadas en un disco. Por eso abrir un original es
 * un permiso aparte (`usuarios.puede_ver_archivos`) y queda anotado en
 * `accesos_archivo`. Ver docs/07.
 *
 * `rut` ES TEXTO, SIN PUNTOS NI GUION
 * ----------------------------------
 * Se valida en el FormRequest, no en la base: la base no sabe de Chile. Guardarlo
 * con puntos haría que "12.345.678-9" y "123456789" fueran dos personas distintas.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nombre
 * @property string $apellido
 * @property string|null $rut
 * @property string|null $telefono
 * @property string|null $correo
 * @property string|null $notas
 * @property bool $activo
 */
class Cliente extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'clientes';

    protected $fillable = [
        'tenant_id',
        'nombre',
        'apellido',
        'rut',
        'telefono',
        'correo',
        'notas',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class, 'cliente_id');
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(Sesion::class, 'cliente_id');
    }

    public function selecciones(): HasMany
    {
        return $this->hasMany(Seleccion::class, 'cliente_id');
    }

    /** Nombre y apellido juntos, que es como se muestra en todas partes. */
    public function nombreCompleto(): string
    {
        return trim($this->nombre . ' ' . $this->apellido);
    }
}