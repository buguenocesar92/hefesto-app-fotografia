<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `estudios`: el tenant.
 *
 * POR QUÉ ESTE MODELO NO USA `PerteneceAlEstudio`
 * ---------------------------------------------
 * Porque es el estudio. No tiene `tenant_id`: es la fila a la que apuntan todas
 * las demás. La razón por la que tampoco lleva Row Level Security está en la
 * migración que la crea, y en `docs/03`.
 *
 * `vw_estudios_publicas` LEÍDA, NO DECLARADA
 * ------------------------------------------
 * Antes del ingreso, lo único que se puede leer es esa vista, con tres columnas.
 * El modelo expone `publicos()` para que el lugar de lectura sea uno solo y no
 * una consulta a la tabla cruda escrita de memoria en un controlador.
 *
 * @property string $id
 * @property string $slug
 * @property string $nombre
 * @property string $nombre_corto
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $correo
 * @property bool $activo
 */
class Estudio extends Model
{
    use HasUuids;

    protected $table = 'estudios';

    protected $fillable = [
        'slug',
        'nombre',
        'nombre_corto',
        'direccion',
        'telefono',
        'correo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * Los estudios activos del selector de ingreso.
     *
     * Devuelve la lista de la vista, no un modelo: la vista no tiene
     * `tenant_id`, y el selector de ingreso no necesita más que tres campos.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function publicos(): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\DB::table('vw_estudios_publicas')
            ->orderBy('nombre')
            ->get(['slug', 'nombre', 'activo']);
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'tenant_id');
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class, 'tenant_id');
    }

    public function paquetes(): HasMany
    {
        return $this->hasMany(Paquete::class, 'tenant_id');
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class, 'tenant_id');
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(Sesion::class, 'tenant_id');
    }

    public function conservaciones(): HasMany
    {
        return $this->hasMany(Conservacion::class, 'tenant_id');
    }
}