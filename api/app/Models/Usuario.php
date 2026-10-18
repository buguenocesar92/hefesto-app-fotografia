<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolEstudio;
use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `usuarios`: la copia de trabajo de la identidad de Authentik.
 *
 * `password` EXISTE Y SIEMPRE ESTÁ EN `NULL`
 * -------------------------------------------
 * Laravel la espera en el modelo. No hay ningún camino en esta aplicación que la
 * escriba: la contraseña vive en Authentik. Ver docs/07.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nombre
 * @property string $correo
 * @property RolEstudio $rol
 * @property bool $puede_ver_archivos
 * @property string|null $authentik_id
 * @property string|null $password
 * @property bool $activo
 * @property \Illuminate\Support\Carbon|null $ultimo_ingreso
 */
class Usuario extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'usuarios';

    protected $fillable = [
        'tenant_id',
        'nombre',
        'correo',
        'rol',
        'puede_ver_archivos',
        'authentik_id',
        'activo',
    ];

    /**
     * La contraseña no se rellena nunca, ni siquiera por accidente: sacarla de
     * `$fillable` no alcanza, porque `password` es un atributo reservado de
     * Laravel y conviene decirlo en voz alta.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'rol' => RolEstudio::class,
            'puede_ver_archivos' => 'boolean',
            'activo' => 'boolean',
            'ultimo_ingreso' => 'datetime',
        ];
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(Sesion::class, 'fotografo_id');
    }

    public function verificaciones(): HasMany
    {
        return $this->hasMany(ConservacionVerificacion::class, 'usuario_id');
    }

    public function abonosRegistrados(): HasMany
    {
        return $this->hasMany(Abono::class, 'registrado_por');
    }

    /** ¿Administra el estudio? */
    public function administra(): bool
    {
        return $this->rol instanceof RolEstudio && $this->rol->administra();
    }

    /** La cuenta con la que se resuelve el usuario en el guard `web`. */
    public function getAuthIdentifierName(): string
    {
        return 'correo';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->correo;
    }

    /** El usuario de Authentik. La fuente de verdad del rol está allá, no acá. */
    public function sincronizadoConAuthentik(): bool
    {
        return $this->authentik_id !== null;
    }
}