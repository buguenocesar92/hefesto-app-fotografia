<?php

declare(strict_types=1);

namespace App\Services\Conservacion;

use App\Enums\ResultadoVerificacion;
use App\Models\Conservacion;
use App\Models\ConservacionVerificacion;
use Illuminate\Support\Collection;

/**
 * Decide qué medio con originales necesita que alguien lo mire.
 *
 * POR QUÉ NO HAY UN NÚMERO DE DÍAS EN EL CÓDIGO
 * ----------------------------------------------
 * Porque no se pudo verificar un número que sirva para el rubro. Un plazo
 * inventado produce avisos falsos, y un sistema que avisa falso entrena al
 * equipo a ignorar el aviso. Con `dias_max_sin_verificar` en `null` no hay
 * aviso automático y la pantalla muestra la fecha de la última verificación.
 * Ver config/fotografia.php y docs/08.
 *
 * LO QUE SÍ SE AVISA SIEMPRE, SIN NINGÚN PLAZO
 * ---------------------------------------------
 * Tres cosas, y ninguna depende de un número:
 *
 *   1. El medio no está activo (desconectado, dañado o destruido).
 *   2. Nunca se verificó.
 *   3. Hay sesiones entregadas cuyos originales están en un medio que no está
 *      operativo.
 *
 * La tercera es la que de verdad duele: una sesión entregada con el disco caído
 * es una promesa que el estudio ya no puede cumplir, y hay que saberlo.
 */
class VerificadorDeConservacion
{
    /**
     * Las conservaciones que hay que revisar, para un estudio.
     *
     * @return Collection<int, Conservacion>
     */
    public function pendientes(string $estudioId, \DateTimeInterface $hoy = null): Collection
    {
        $hoy = $hoy ?? now();

        $diasMaximos = config('fotografia.conservacion.dias_max_sin_verificar');

        return Conservacion::query()
            ->where('estado', '<>', 'activa')
            ->orderBy('nombre')
            ->get()
            ->concat(
                Conservacion::query()
                    ->where('estado', 'activa')
                    ->whereNull('ultima_verificacion_en')
                    ->orderBy('nombre')
                    ->get()
            )
            ->concat(
                $diasMaximos === null
                    ? collect()
                    : Conservacion::query()
                        ->where('estado', 'activa')
                        ->whereNotNull('ultima_verificacion_en')
                        ->get()
                        ->filter(static function (Conservacion $c) use ($diasMaximos, $hoy): bool {
                            $dias = $c->diasSinVerificar($hoy);

                            return $dias !== null && $dias > $diasMaximos;
                        })
            )
            ->unique(static fn (Conservacion $c): string => (string) $c->id)
            ->sortBy('nombre')
            ->values();
    }

    /**
     * Las sesiones ya entregadas cuyos originales están en un medio que no
     * está operativo.
     *
     * @return Collection<int, \App\Models\Sesion>
     */
    public function sesionesEnRiesgo(string $estudioId): Collection
    {
        return \App\Models\Sesion::query()
            ->with('conservacion')
            ->where('estado', 'entregada')
            ->whereNotNull('conservacion_id')
            ->get()
            ->filter(static function (\App\Models\Sesion $sesion): bool {
                return $sesion->conservacion !== null && ! $sesion->conservacion->estaOperativa();
            });
    }

    /**
     * Escribe una revisión.
     *
     * Solo se actualiza `ultima_verificacion_en` cuando la revisión SALIÓ BIEN.
     * Registrar como "verificada hoy" una revisión que encontró faltantes dejaría
     * el reloj reiniciado y el faltante escondido hasta el próximo mes.
     */
    public function registrar(
        Conservacion $conservacion,
        ResultadoVerificacion $resultado,
        ?string $usuarioId = null,
        ?int $archivosRevisados = null,
        int $archivosFaltantes = 0,
        ?string $observaciones = null,
    ): ConservacionVerificacion {
        $verificacion = $conservacion->verificaciones()->create([
            'tenant_id' => $conservacion->tenant_id,
            'usuario_id' => $usuarioId,
            'fecha' => now(),
            'resultado' => $resultado,
            'archivos_revisados' => $archivosRevisados,
            'archivos_faltantes' => $archivosFaltantes,
            'observaciones' => $observaciones,
        ]);

        if ($resultado === ResultadoVerificacion::Correcta) {
            $conservacion->ultima_verificacion_en = $verificacion->fecha;
            $conservacion->save();
        } elseif ($resultado === ResultadoVerificacion::ConFaltantes) {
            // El medio con faltantes no vuelve a `activa` por su cuenta: eso es
            // decisión de una persona, que puede haber recuperado los archivos
            // desde otra copia.
            $conservacion->estado = \App\Enums\EstadoConservacion::Danada;
            $conservacion->save();
        }

        return $verificacion;
    }

    /**
     * El informe que se muestra, en palabras y con números.
     *
     * @return array<string, mixed>
     */
    public function informe(string $estudioId): array
    {
        $pendientes = $this->pendientes($estudioId);
        $enRiesgo = $this->sesionesEnRiesgo($estudioId);

        $diasMaximos = config('fotografia.conservacion.dias_max_sin_verificar');

        return [
            'dias_max_sin_verificar' => $diasMaximos,
            'plazo_configurado' => $diasMaximos !== null,
            'pendientes' => $pendientes->map(static fn (Conservacion $c): array => [
                'id' => (string) $c->id,
                'nombre' => (string) $c->nombre,
                'estado' => $c->estado->value,
                'estado_etiqueta' => $c->estado->etiqueta(),
                'tipo_medio' => (string) $c->tipo_medio,
                'ubicacion' => (string) $c->ubicacion,
                'sesiones_asignadas' => (int) $c->sesiones_asignadas,
                'dias_sin_verificar' => $c->diasSinVerificar(),
                'ultima_verificacion_en' => $c->ultima_verificacion_en?->toIso8601String(),
            ])->all(),
            'sesiones_en_riesgo' => $enRiesgo->map(static fn ($s): array => [
                'id' => (string) $s->id,
                'titulo' => (string) $s->titulo,
                'fecha_prometida' => $s->fecha_prometida?->format('Y-m-d'),
                'conservacion' => $s->conservacion?->nombre,
                'estado_conservacion' => $s->conservacion?->estado->etiqueta(),
            ])->all(),
        ];
    }
}