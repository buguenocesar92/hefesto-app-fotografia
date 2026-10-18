<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Marca un modelo como dato de un estudio.
 *
 * POR QUÉ EXISTE UNA SOLA TRAZA Y NO CATORCE
 * ------------------------------------------
 * Todas las tablas de negocio tienen `tenant_id` y todas pasan por la misma
 * Row Level Security. Si cada modelo escribiera su propia relación y su propio
 * alcance, una diferencia de una letra en uno de ellos dejaría una tabla
 * filtrando datos de otro estudio sin que ninguna prueba lo notara.
 *
 * LA COMPARACIÓN EN LAS POLICIES
 * ------------------------------
 * `esDelMismoEstudio()` es la que usan todas las Policies. Y hace lo que
 * recontar: si el recurso no tiene `tenant_id`, NO es del mismo estudio. Un
 * modelo mal escrito no es un acceso abierto: es un rechazo.
 */
trait PerteneceAlEstudio
{
    /**
     * ¿Este registro y el de `$usuario` son del mismo estudio?
     *
     * @param  object  $recurso  un modelo con `tenant_id`
     */
    public function esDelMismoEstudio(?object $recurso): bool
    {
        if ($recurso === null) {
            return false;
        }

        if (! property_exists($recurso, 'tenant_id')) {
            return false;
        }

        if ($recurso->tenant_id === null) {
            return false;
        }

        return (string) $recurso->tenant_id === (string) $this->tenant_id;
    }

    /** El estudio al que pertenece el registro. */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Estudio::class, 'tenant_id');
    }

    /**
     * Obliga a que la consulta declare el estudio.
     *
     * No es la Row Level Security: esa está en la base y no se puede saltar desde
     * PHP. Esto es lo que impide, por ejemplo, que un informe se quede fuera del
     * contexto y devuelva todas las filas de todos los estudios.
     */
    public function scopeDelEstudio(Builder $consulta, string $estudioId): Builder
    {
        return $consulta->where($consulta->qualifyColumn('tenant_id'), $estudioId);
    }

    /**
     * La conexión de auditoría: una aparte, con su propio juego de variables.
     *
     * El registro de un acceso a un original tiene que sobrevivir a la reversa de
     * la petición que lo denegó, y con la misma conexión se perdería justo el
     * registro que más importa. Ver App\Services\Tenancy\AuditorDeAcceso.
     */
    protected function conexionDeAuditoria(): string
    {
        return config('database.default') === 'auditoria'
            ? config('database.default')
            : 'auditoria';
    }

    /**
     * Falla ruidosamente si el registro no trae `tenant_id`.
     *
     * Un modelo sin `tenant_id` no es un registro sin estudio: es un error de
     * tipeo, y dejarlo pasar sería la forma más barata de romper el aislamiento.
     */
    public function exigirEstudio(): string
    {
        if ($this->tenant_id === null) {
            throw new RuntimeException(
                static::class . ' se guardó sin tenant_id. Un registro sin estudio no se puede proteger con RLS.'
            );
        }

        return (string) $this->tenant_id;
    }
}