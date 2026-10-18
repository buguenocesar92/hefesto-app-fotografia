<?php

declare(strict_types=1);

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarClienteRequest;
use App\Models\Cliente;
use App\Models\Sesion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Las personas de las que son las fotos.
 *
 * POR QUÉ ESTA FICHA ES MÁS DELICADA DE LO QUE PARECE
 * --------------------------------------------------
 * Solo tiene nombre, RUT, teléfono y correo. Y sin embargo no la ve todo el
 * estudio: quien puede ver fotos de los hijos de una familia es quien la persona
 * autorizó para eso. Ver `ClientePolicy` y docs/07.
 *
 * LO QUE ESTA FICHA NO MUESTRA
 * ---------------------------
 * Los archivos. Las fotos de una sesión tienen su propio permiso, el de
 * `usuarios.puede_ver_archivos`, y se auditan aparte. Ver docs/08.
 */
class ClienteController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('viewAny', Cliente::class);

        $busqueda = $peticion->query('buscar');

        $consulta = Cliente::query()->orderBy('nombre')->orderBy('apellido');

        if (is_string($busqueda) && trim($busqueda) !== '') {
            $termino = '%' . trim($busqueda) . '%';

            $consulta->where(static function ($q) use ($termino): void {
                $q->where('nombre', 'ilike', $termino)
                    ->orWhere('apellido', 'ilike', $termino)
                    ->orWhere('rut', 'ilike', $termino);
            });
        }

        return response()->json([
            'clientes' => $consulta->limit(300)->get()->map(static fn (Cliente $c): array => [
                'id' => (string) $c->id,
                'nombre' => (string) $c->nombre,
                'apellido' => (string) $c->apellido,
                'nombre_completo' => $c->nombreCompleto(),
                'rut' => $c->rut,
                'telefono' => $c->telefono,
                'correo' => $c->correo,
                'activo' => (bool) $c->activo,
            ])->all(),
        ]);
    }

    public function show(Request $peticion, Cliente $cliente): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('view', $cliente);

        $sesiones = Sesion::query()
            ->where('cliente_id', $cliente->id)
            ->orderByDesc('fecha_prometida')
            ->limit(50)
            ->get();

        return response()->json([
            'cliente' => [
                'id' => (string) $cliente->id,
                'nombre' => (string) $cliente->nombre,
                'apellido' => (string) $cliente->apellido,
                'nombre_completo' => $cliente->nombreCompleto(),
                'rut' => $cliente->rut,
                'telefono' => $cliente->telefono,
                'correo' => $cliente->correo,
                'notas' => $cliente->notas,
                'activo' => (bool) $cliente->activo,
            ],

            'sesiones' => $sesiones->map(static fn (Sesion $s): array => [
                'id' => (string) $s->id,
                'titulo' => (string) $s->titulo,
                'tipo' => $s->tipo->value,
                'tipo_etiqueta' => $s->tipo->etiqueta(),
                'fecha_prometida' => $s->fecha_prometida?->format('d-m-Y'),
                'estado' => $s->estado->value,
                'estado_etiqueta' => $s->estado->etiqueta(),
                // Un tipo de sesión puede incluir a alguien menor de edad. No se
                // bloquea nada por eso: el aviso es para que quien atiende lo
                // tenga presente. Ver docs/07.
                'puede_incluir_menores' => $s->tipo->puedeIncluirMenores(),
            ])->all(),
        ]);
    }

    public function store(GuardarClienteRequest $peticion): JsonResponse
    {
        $usuario = $this->usuarioObligatorio($peticion);

        $this->authorize('create', Cliente::class);

        $datos = $peticion->validated();

        $cliente = Cliente::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'nombre' => $datos['nombre'],
            'apellido' => $datos['apellido'],
            'rut' => $datos['rut'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'correo' => $datos['correo'] ?? null,
            'notas' => $datos['notas'] ?? null,
            'activo' => $datos['activo'] ?? true,
        ]);

        return response()->json([
            'mensaje' => 'Persona registrada.',
            'cliente_id' => (string) $cliente->id,
        ], 201);
    }

    public function update(GuardarClienteRequest $peticion, Cliente $cliente): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('update', $cliente);

        $datos = $peticion->validated();

        $cliente->fill([
            'nombre' => $datos['nombre'],
            'apellido' => $datos['apellido'],
            'rut' => $datos['rut'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'correo' => $datos['correo'] ?? null,
            'notas' => $datos['notas'] ?? null,
        ]);

        if (array_key_exists('activo', $datos)) {
            $cliente->activo = (bool) $datos['activo'];
        }

        $cliente->save();

        return response()->json([
            'mensaje' => 'Ficha actualizada.',
            'cliente_id' => (string) $cliente->id,
        ]);
    }

    /**
     * Desactiva la ficha. Nunca la borra.
     *
     * Una persona que contrata en 2026 puede volver en 2029, y para entonces su ficha
     * tiene que seguir ahí con sus sesiones. Por eso esto no es un `DELETE` de
     * fila sino un cambio de estado. Ver docs/02.
     */
    public function destroy(Request $peticion, Cliente $cliente): JsonResponse
    {
        $this->usuarioObligatorio($peticion);

        $this->authorize('delete', $cliente);

        $cliente->activo = false;
        $cliente->save();

        return response()->json([
            'mensaje' => 'Ficha desactivada. Se conserva el historial de sus sesiones.',
        ]);
    }
}