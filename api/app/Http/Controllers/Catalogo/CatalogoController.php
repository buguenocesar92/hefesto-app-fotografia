<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalogo;

use App\Enums\RolEstudio;
use App\Http\Controllers\Controller;
use App\Models\Laboratorio;
use App\Models\Paquete;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogos: paquetes, laboratorios y cuentas.
 *
 * POR QUÉ NO HAY UN CRUD GENÉRICO
 * ------------------------------
 * Porque cada catálogo tiene una regla que un CRUD genérico no tendría: los
 * paquetes congelan su precio al firmarse, los laboratorios son proveedores que
 * se desactivan y no se borran, y las cuentas tienen una comprobación de que no
 * quede ninguna sin administración. Un controlador genérico no podría con ninguna
 * de las tres. Ver docs/03.
 */
class CatalogoController extends Controller
{
    /**
     * Los paquetes que el estudio ofrece.
     *
     * La ven las tres personas, hasta fotografía: es la carta.
     */
    public function paquetes(Request $peticion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('viewAny', Paquete::class);

        return response()->json([
            'paquetes' => Paquete::query()
                ->where('activo', true)
                ->orderBy('nombre')
                ->get()
                ->map(static fn (Paquete $p): array => [
                    'id' => (string) $p->id,
                    'codigo' => (string) $p->codigo,
                    'nombre' => (string) $p->nombre,
                    'descripcion' => $p->descripcion,
                    'precio_referencia' => (int) $p->precio_referencia,
                ])->all(),
        ]);
    }

    /**
     * Guarda un paquete.
     *
     * `precio_referencia` es solo el punto de partida: al contratar se copian las
     * líneas con su precio, y ahí queda congelado lo que firmó la persona. Ver
     * docs/06.
     */
    public function guardarPaquete(Request $peticion): JsonResponse
    {
        $usuario = $this->usuarioObligatorio($peticion);

        $this->authorize('create', Paquete::class);

        $validados = $peticion->validate([
            'codigo' => ['required', 'string', 'max:32'],
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'precio_referencia' => ['required', 'integer', 'min:0'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $paquete = Paquete::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'codigo' => $validados['codigo'],
            'nombre' => $validados['nombre'],
            'descripcion' => $validados['descripcion'] ?? null,
            'precio_referencia' => $validados['precio_referencia'],
            'activo' => $validados['activo'] ?? true,
        ]);

        return response()->json([
            'mensaje' => 'Paquete guardado.',
            'paquete_id' => (string) $paquete->id,
        ], 201);
    }

    /**
     * Los laboratorios.
     *
     * La lista la ven las tres personas: es a quién se le llama. Dar de alta uno
     * nuevo es de administración, porque ahí va el teléfono de un proveedor y la
     * decisión de trabajar con él.
     */
    public function laboratorios(Request $peticion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        return response()->json([
            'laboratorios' => Laboratorio::query()
                ->where('activo', true)
                ->orderBy('nombre')
                ->get()
                ->map(static fn (Laboratorio $l): array => [
                    'id' => (string) $l->id,
                    'nombre' => (string) $l->nombre,
                    'contacto' => $l->contacto,
                    'telefono' => $l->telefono,
                    'correo' => $l->correo,
                ])->all(),
        ]);
    }

    public function guardarLaboratorio(Request $peticion): JsonResponse
    {
        $usuario = $this->usuarioObligatorio($peticion);

        $this->authorize('administrarLaboratorio', Laboratorio::class);

        $validados = $peticion->validate([
            'nombre' => ['required', 'string', 'max:160'],
            'contacto' => ['nullable', 'string', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:32'],
            'correo' => ['nullable', 'email', 'max:160'],
        ]);

        $laboratorio = Laboratorio::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'nombre' => $validados['nombre'],
            'contacto' => $validados['contacto'] ?? null,
            'telefono' => $validados['telefono'] ?? null,
            'correo' => $validados['correo'] ?? null,
            'activo' => true,
        ]);

        return response()->json([
            'mensaje' => 'Laboratorio registrado.',
            'laboratorio_id' => (string) $laboratorio->id,
        ], 201);
    }

    /**
     * Las cuentas del estudio.
     *
     * Solo de administración, y solo de este estudio: la Row Level Security ya no
     * dejaría ver las de otro, pero la `Policy` lo dice antes de tocar la fila.
     */
    public function usuarios(Request $peticion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('viewAny', Usuario::class);

        return response()->json([
            'usuarios' => Usuario::query()
                ->orderBy('nombre')
                ->get()
                ->map(static fn (Usuario $u): array => [
                    'id' => (string) $u->id,
                    'nombre' => (string) $u->nombre,
                    'correo' => (string) $u->correo,
                    'rol' => $u->rol->value,
                    'rol_etiqueta' => $u->rol->etiqueta(),
                    'puede_ver_archivos' => (bool) $u->puede_ver_archivos,
                    'activo' => (bool) $u->activo,
                    'ultimo_ingreso' => $u->ultimo_ingreso?->toIso8601String(),
                ])->all(),

            'roles' => array_map(static fn (RolEstudio $r): array => [
                'valor' => $r->value,
                'etiqueta' => $r->etiqueta(),
            ], RolEstudio::cases()),
        ]);
    }
}