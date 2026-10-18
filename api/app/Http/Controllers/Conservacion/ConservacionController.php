<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conservacion;

use App\Enums\ResultadoVerificacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarConservacionRequest;
use App\Models\Conservacion;
use App\Models\ConservacionVerificacion;
use App\Models\Sesion;
use App\Services\Conservacion\VerificadorDeConservacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La conservación de los archivos.
 *
 * POR QUÉ ESTA PANTALLA EXISTE Y NO ES UN CAMPO
 * ---------------------------------------------
 * Un estudio entrega las fotos "de por vida". Si el disco muere, se pierden las
 * sesiones de tres años, y esa es la pérdida más grande del rubro. Un campo con
 * la ruta del disco no avisa de nada: el texto sigue ahí diciendo que todo está
 * bien.
 *
 * Por eso esta pantalla muestra el ESTADO del medio, la fecha de la última
 * verificación y las sesiones ya entregadas cuyos originales están en un medio
 * que no está operativo. Esa última lista es la que duele: es una promesa que el
 * estudio ya no puede cumplir. Ver docs/08.
 *
 * LO QUE ESTA PANTALLA HACE ES PREGUNTAR, NO PURGAR
 * -------------------------------------------------
 * No borra nada. La Ley 19.628 obliga a que la conservación tenga una finalidad
 * declarada, y no se pudo verificar en este entorno el plazo que corresponde a
 * los originales de un estudio fotográfico. Los plazos de retención quedan en
 * `null` y no hay ninguna tarea programada que purgue. Ver docs/07.
 */
class ConservacionController extends Controller
{
    public function __construct(private readonly VerificadorDeConservacion $verificador) {}

    public function index(Request $peticion): JsonResponse
    {
        $usuario = $this->usuarioObligatorio($peticion);

        $this->authorize('viewAny', Conservacion::class);

        return response()->json([
            'conservaciones' => Conservacion::query()
                ->orderBy('nombre')
                ->get()
                ->map(static fn (Conservacion $c): array => [
                    'id' => (string) $c->id,
                    'nombre' => (string) $c->nombre,
                    'tipo_medio' => (string) $c->tipo_medio,
                    'ubicacion' => (string) $c->ubicacion,
                    'estado' => $c->estado->value,
                    'estado_etiqueta' => $c->estado->etiqueta(),
                    'operativa' => $c->estaOperativa(),
                    'ultima_verificacion_en' => $c->ultima_verificacion_en?->toIso8601String(),
                    'dias_sin_verificar' => $c->diasSinVerificar(),
                    'necesita_revision' => $c->necesitaRevision(),
                    'sesiones_asignadas' => (int) $c->sesiones_asignadas,
                ])->all(),

            'tipos_de_medio' => Conservacion::TIPOS_DE_MEDIO,

            'informe' => $this->verificador->informe((string) $usuario->tenant_id),

            'configuracion' => [
                'dias_entre_verificaciones' => (int) config('fotografia.conservacion.dias_entre_verificaciones'),
                'dias_max_sin_verificar' => config('fotografia.conservacion.dias_max_sin_verificar'),
                // Con `null` el sistema no avisa por antigüedad y la pantalla lo
                // declara. Ver config/fotografia.php y docs/08.
                'plazo_configurado' => config('fotografia.conservacion.dias_max_sin_verificar') !== null,
            ],
        ]);
    }

    public function store(GuardarConservacionRequest $peticion): JsonResponse
    {
        $usuario = $this->usuarioObligatorio($peticion);

        $this->authorize('create', Conservacion::class);

        $datos = $peticion->validated();

        $conservacion = Conservacion::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'nombre' => $datos['nombre'],
            'tipo_medio' => $datos['tipo_medio'],
            'ubicacion' => $datos['ubicacion'],
            'estado' => $datos['estado'],
            'bytes_declarados' => $datos['bytes_declarados'] ?? null,
            'notas' => $datos['notas'] ?? null,
        ]);

        return response()->json([
            'mensaje' => 'Medio registrado.',
            'conservacion_id' => (string) $conservacion->id,
        ], 201);
    }

    /**
     * Cambia el estado del medio.
     *
     * Es una afirmación sobre la custodia, y por eso es de administración. Ver
     * `App\Policies\ConservacionPolicy`.
     */
    public function update(Request $peticion, Conservacion $conservacion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('update', $conservacion);

        $estado = $peticion->input('estado');

        $permitidos = ['activa', 'desconectada', 'danada', 'destruida'];

        if (! is_string($estado) || ! in_array($estado, $permitidos, true)) {
            return response()->json([
                'mensaje' => 'Ese estado no existe.',
                'errores' => ['estado' => ['Los estados posibles son: ' . implode(', ', $permitidos) . '.']],
            ], 422);
        }

        // Pasar a `destruida` es decir que los originales de una familia se
        // perdieron. Eso no se escribe sin motivo.
        if ($estado === 'destruida') {
            $motivo = $peticion->input('motivo');

            if (! is_string($motivo) || trim($motivo) === '') {
                return response()->json([
                    'mensaje' => 'Marcar un medio como destruido exige escribir qué pasó. '
                        . 'Un estudio que marca un disco destruido sin explicación no puede después '
                        . 'decir cuándo dejó de tener las fotos.',
                ], 422);
            }

            $conservacion->notas = trim((string) $conservacion->notas . "\nDestruido: " . trim($motivo));
        }

        $conservacion->estado = $estado;

        $notas = $peticion->input('notas');

        if (is_string($notas) && $notas !== '') {
            $conservacion->notas = $notas;
        }

        $conservacion->save();

        return response()->json([
            'mensaje' => 'Estado del medio actualizado.',
            'conservacion_id' => (string) $conservacion->id,
            'estado' => $conservacion->estado->value,
        ]);
    }

    /**
     * Registra una verificación.
     *
     * `no_se_pudo_verificar` NO ES UN FALLO: es que el equipo estaba en otra
     * ciudad o el estudio estaba cerrado. Registrar eso como fallo haría que el
     * estudio se cansara de las alertas. Ver `App\Enums\ResultadoVerificacion`.
     */
    public function verificar(Request $peticion, Conservacion $conservacion): JsonResponse
    {
        $usuario = $this->usuarioObligatorio($peticion);

        $this->authorize('verificar', $conservacion);

        $resultado = ResultadoVerificacion::tryFrom((string) $peticion->input('resultado', ''));

        if ($resultado === null) {
            return response()->json([
                'mensaje' => 'Falta decir cómo salió la verificación.',
                'errores' => ['resultado' => ['Los resultados posibles son: correcta, con_faltantes o no_se_pudo_verificar.']],
            ], 422);
        }

        $revisados = $peticion->input('archivos_revisados');

        $verificacion = $this->verificador->registrar(
            $conservacion,
            $resultado,
            (string) $usuario->id,
            is_numeric($revisados) ? (int) $revisados : null,
            (int) $peticion->input('archivos_faltantes', 0),
            is_string($peticion->input('observaciones')) ? $peticion->input('observaciones') : null,
        );

        return response()->json([
            'mensaje' => $resultado->etiqueta(),
            'verificacion_id' => (string) $verificacion->id,
            'resultado' => $resultado->value,
            'conservacion' => $conservacion->refresh()->estado->value,
            'ultima_verificacion_en' => $conservacion->ultima_verificacion_en?->toIso8601String(),
        ]);
    }

    /**
     * El historial de revisiones de un medio.
     */
    public function historial(Request $peticion, Conservacion $conservacion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('view', $conservacion);

        return response()->json([
            'verificaciones' => ConservacionVerificacion::query()
                ->where('conservacion_id', $conservacion->id)
                ->with('usuario:id,nombre')
                ->orderByDesc('fecha')
                ->limit(100)
                ->get()
                ->map(static fn (ConservacionVerificacion $v): array => [
                    'id' => (string) $v->id,
                    'fecha' => $v->fecha?->toIso8601String(),
                    'resultado' => $v->resultado->value,
                    'resultado_etiqueta' => $v->resultado->etiqueta(),
                    'archivos_revisados' => $v->archivos_revisados,
                    'archivos_faltantes' => (int) $v->archivos_faltantes,
                    'observaciones' => $v->observaciones,
                    'usuario' => $v->usuario?->nombre,
                ])->all(),
        ]);
    }

    /**
     * Las sesiones ya entregadas cuyo medio no está operativo.
     */
    public function sesionesEnRiesgo(Request $peticion): JsonResponse
    {
        $usuario = $this->usuarioObligatorio($peticion);

        $this->authorize('viewAny', Conservacion::class);

        return response()->json([
            'sesiones' => Sesion::query()
                ->with('conservacion')
                ->where('estado', 'entregada')
                ->whereNotNull('conservacion_id')
                ->get()
                ->map(static fn (Sesion $s): array => [
                    'id' => (string) $s->id,
                    'titulo' => (string) $s->titulo,
                    'fecha_prometida' => $s->fecha_prometida?->format('d-m-Y'),
                    'conservacion' => $s->conservacion?->nombre,
                    'estado_conservacion' => $s->conservacion?->estado->etiqueta(),
                    'en_riesgo' => $s->conservacion !== null && ! $s->conservacion->estaOperativa(),
                ])->all(),
            'estudio' => (string) $usuario->tenant_id,
        ]);
    }
}