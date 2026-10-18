<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoSeleccion;
use App\Enums\EstadoSesion;
use App\Enums\TipoSesion;
use App\Models\Concerns\PerteneceAlEstudio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * `sesiones`: las tomas de fotos con fecha prometida.
 *
 * POR QUÉ DOS FECHAS
 * ------------------
 * `fecha_prometida` es la que se le dijo a la persona, y es un compromiso.
 * `fecha_realizada` es la que pasó, y puede ser distinta. Sobrescribir la
 * primera con la segunda borra el compromiso, que es exactamente lo que no se
 * puede perder cuando alguien pregunta en marzo por qué no le llegó.
 *
 * `pendiente_seleccion` NO ES UN ERROR
 * ------------------------------------
 * Es el estado normal de una sesión que se tomó y en la que la persona todavía
 * no eligió. Ver docs/03.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $contrato_id
 * @property string $cliente_id
 * @property string|null $fotografo_id
 * @property TipoSesion $tipo
 * @property string $titulo
 * @property string|null $lugar
 * @property \Illuminate\Support\Carbon $fecha_prometida
 * @property \Illuminate\Support\Carbon|null $fecha_realizada
 * @property EstadoSesion $estado
 * @property int|null $duracion_minutos
 * @property \Illuminate\Support\Carbon|null $fecha_prometida_entrega
 * @property string|null $conservacion_id
 * @property string|null $notas
 */
class Sesion extends Model
{
    use HasUuids;
    use PerteneceAlEstudio;

    protected $table = 'sesiones';

    protected $fillable = [
        'tenant_id',
        'contrato_id',
        'cliente_id',
        'fotografo_id',
        'tipo',
        'titulo',
        'lugar',
        'fecha_prometida',
        'fecha_realizada',
        'estado',
        'duracion_minutos',
        'fecha_prometida_entrega',
        'conservacion_id',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoSesion::class,
            'estado' => EstadoSesion::class,
            'fecha_prometida' => 'date',
            'fecha_realizada' => 'date',
            'fecha_prometida_entrega' => 'date',
            'duracion_minutos' => 'integer',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class, 'contrato_id');
    }

    public function fotografo(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'fotografo_id');
    }

    public function conservacion(): BelongsTo
    {
        return $this->belongsTo(Conservacion::class, 'conservacion_id');
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(Foto::class, 'sesion_id');
    }

    /** La selección viva de esta sesión. Es una a la vez. */
    public function seleccion(): HasOne
    {
        return $this->hasOne(Seleccion::class, 'sesion_id');
    }

    public function pedidosLaboratorio(): HasMany
    {
        return $this->hasMany(PedidoLaboratorio::class, 'sesion_id');
    }

    /**
     * ¿Hay una selección confirmada?
     *
     * Es la condición que impide entregar sin que nadie haya elegido. El
     * servicio de estado vuelve a comprobarlo antes de escribir, porque entre
     * que se carga la pantalla y se aprieta el botón, la selección puede haber
     * cambiado.
     */
    public function tieneSeleccionConfirmada(): bool
    {
        return $this->seleccion()
            ->where('estado', EstadoSeleccion::Confirmada->value)
            ->exists();
    }

    /** ¿La sesión está esperando que la persona elija? */
    public function esperaSeleccion(): bool
    {
        return $this->estado === EstadoSesion::PendienteSeleccion;
    }

    /**
     * ¿La fecha prometida de entrega ya pasó y la sesión no está entregada?
     *
     * No usa el reloj del servidor con la hora del día: la promesa es por día.
     */
    public function estaAtrasada(\DateTimeInterface $hoy = null): bool
    {
        if ($this->estado === EstadoSesion::Entregada) {
            return false;
        }

        if ($this->fecha_prometida_entrega === null) {
            return false;
        }

        $hoy = $hoy ?? today();

        return $this->fecha_prometida_entrega->startOfDay()->lessThan(
            \Illuminate\Support\Carbon::instance($hoy)->startOfDay()
        );
    }
}