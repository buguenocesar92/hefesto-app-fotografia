<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoConservacion;
use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `conservaciones`: dónde están los originales y si ese medio sigue vivo.
 *
 * POR QUÉ ESTO NO ES UN CAMPO DE TEXTO EN LA SESIÓN
 * -------------------------------------------------
 * Porque un texto con la ruta del disco no responde a la única pregunta que
 * importa: ¿el disco sigue vivo? El texto va a seguir ahí, diciendo que todo
 * está bien, el día después de que el disco se haya caído. Y esa es la pérdida
 * más grande del rubro: las sesiones de tres años, perdidas de una vez.
 *
 * CON ESTA TABLA EL SISTEMA PUEDE:
 *   - Filtrar las conservaciones que están `desconectada` o `danada`.
 *   - Avisar cuando pasó mucho tiempo sin verificar.
 *   - Decir, al abrir una sesión, que sus originales están en un medio que no
 *     está operativo.
 *
 * `tipo_medio` ES UNA LISTA CERRADA A PROPÓSITO
 * ---------------------------------------------
 * Disco local, red de área local, almacenamiento en la nube, cinta. Agregar un
 * medio nuevo es una decisión sobre cómo se custodia un archivo que pertenece a
 * otras personas, y no algo que se haga desde una pantalla. Ver docs/08.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nombre
 * @property string $tipo_medio
 * @property string $ubicacion
 * @property EstadoConservacion $estado
 * @property \Illuminate\Support\Carbon|null $ultima_verificacion_en
 * @property int|null $bytes_declarados
 * @property int $sesiones_asignadas
 * @property string|null $notas
 */
class Conservacion extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'conservaciones';

    /**
     * Los medios con los que el sistema sabe trabajar.
     *
     * La lista se declara en PHP y no en la base porque es una decisión del
     * software, no un dato del estudio. Un medio que no esté acá no se puede
     * registrar, y eso es lo que impide que alguien escriba "en el escritorio de
     * la recepción" en el campo de ubicación.
     *
     * @var list<string>
     */
    public const TIPOS_DE_MEDIO = [
        'disco_local',
        'red_area_local',
        'almacenamiento_nube',
        'cinta',
    ];

    protected $fillable = [
        'tenant_id',
        'nombre',
        'tipo_medio',
        'ubicacion',
        'estado',
        'ultima_verificacion_en',
        'bytes_declarados',
        'sesiones_asignadas',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoConservacion::class,
            'ultima_verificacion_en' => 'datetime',
            'bytes_declarados' => 'integer',
            'sesiones_asignadas' => 'integer',
        ];
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(Sesion::class, 'conservacion_id');
    }

    public function verificaciones(): HasMany
    {
        return $this->hasMany(ConservacionVerificacion::class, 'conservacion_id');
    }

    /** La última verificación que se registró. */
    public function ultimaVerificacion(): ?ConservacionVerificacion
    {
        return $this->verificaciones()->latest('fecha')->first();
    }

    /**
     * ¿Hace cuánto que no se verifica?
     *
     * Con `null` se devuelve `null`: no hay fecha, no hay días contados. Fabricar
     * un cero habría que leer "recién verificada" en una conservación que nunca
     * se verificó, que es el peor defecto posible en esta tabla.
     */
    public function diasSinVerificar(\DateTimeInterface $hoy = null): ?int
    {
        if ($this->ultima_verificacion_en === null) {
            return null;
        }

        $hoy = $hoy ?? now();

        return (int) $this->ultima_verificacion_en->diffInDays(
            \Illuminate\Support\Carbon::instance($hoy)
        );
    }

    /**
     * ¿Esta conservación necesita que una persona la mire?
     *
     * Es `true` en los tres casos que importan: el medio no está activo, nunca se
     * verificó, o pasó el plazo configurado. Con el plazo en `null` el último
     * caso nunca se activa, y eso se declara en pantalla en vez de inventar un
     * número. Ver config/fotografia.php.
     */
    public function necesitaRevision(\DateTimeInterface $hoy = null): bool
    {
        if ($this->estado->exigeRevision()) {
            return true;
        }

        if ($this->ultima_verificacion_en === null) {
            return true;
        }

        $diasMaximos = config('fotografia.conservacion.dias_max_sin_verificar');

        if ($diasMaximos === null) {
            return false;
        }

        $dias = $this->diasSinVerificar($hoy);

        return $dias !== null && $dias > $diasMaximos;
    }

    /** ¿Se pueden entregar fotos que están en esta conservación? */
    public function estaOperativa(): bool
    {
        return $this->estado->estaOperativa();
    }
}