<?php

declare(strict_types=1);

namespace App\Services\Seleccion;

use App\Enums\EstadoSeleccion;
use App\Enums\EstadoSesion;
use App\Models\Foto;
use App\Models\Seleccion;
use App\Models\Sesion;
use App\Services\Sesiones\GestorDeEstadoDeSesion;
use DomainException;

/**
 * Registrar lo que eligió la persona.
 *
 * UNA SESIÓN TIENE UNA SELECCIÓN VIVA
 * -----------------------------------
 * Volver a elegir no crea una segunda fila: actualiza la que hay. Si se creara
 * una nueva, "¿cuándo confirmó la persona?" quedaría repartido entre varias
 * filas y no se podría responder.
 *
 * CONFIRMAR MUEVE LA SESIÓN, Y ESO ES LO IMPORTANTE
 * -------------------------------------------------
 * Cuando la selección queda `confirmada`, la sesión pasa a `pendiente_seleccion`
 * si no estaba más allá. Antes de eso la sesión queda en `realizada`, que quiere
 * decir "tomada, sin elegir". De ahí en adelante la pantalla de selección es la
 * que manda.
 *
 * `pendiente_seleccion` NO ES UN ERROR: es el estado normal. Ver docs/03.
 */
class RegistradorDeSeleccion
{
    public function __construct(private readonly GestorDeEstadoDeSesion $gestor) {}

    /**
     * Guarda el conjunto de fotos elegidas.
     *
     * @param  list<string>  $idsDeFotos
     */
    public function registrar(Sesion $sesion, string $clienteId, array $idsDeFotos, bool $confirmar = false): Seleccion
    {
        $ids = array_values(array_unique(array_filter($idsDeFotos, static fn ($id): bool => is_string($id) && $id !== '')));

        // Las fotos tienen que ser de ESTA sesión. Sin esta comprobación, un
        // identificadorTraversal de otra sesión entraría en la selección y se
        // imprimiría una foto que no es de este trabajo.
        $fotosDeLaSesion = $sesion->fotos()->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $ajenas = array_values(array_diff($ids, $fotosDeLaSesion));

        if ($ajenas !== []) {
            throw new DomainException(
                'Hay fotos en la selección que no pertenecen a esta sesión. Se rechazó la lista sin tocarla.'
            );
        }

        /** @var Seleccion $seleccion */
        $seleccion = Seleccion::query()->updateOrCreate(
            [
                'tenant_id' => $sesion->tenant_id,
                'sesion_id' => $sesion->id,
            ],
            [
                'cliente_id' => $clienteId,
                'estado' => $confirmar ? EstadoSeleccion::Confirmada : EstadoSeleccion::EnCurso,
                'cantidad_seleccionada' => count($ids),
                'fecha_confirmacion' => $confirmar ? now() : null,
            ]
        );

        // Se reemplaza el detalle completo: la persona cambió de opinión, y el
        // detalle es el reflejo de su opinión actual, no un registro de todo lo
        // que fue marcando mientras miraba.
        $seleccion->detalles()->delete();

        foreach ($ids as $id) {
            $seleccion->detalles()->create([
                'tenant_id' => $sesion->tenant_id,
                'foto_id' => $id,
                'elegida' => true,
            ]);
        }

        if ($confirmar && ! $seleccion->detalles()->exists()) {
            throw new DomainException(
                'No se puede confirmar una selección sin ninguna foto elegida. '
                . 'Si la persona quiere dejar las cosas como están, hay que decirlo en la sesión.'
            );
        }

        $this->acompanarElEstadoDeLaSesion($sesion, $seleccion);

        return $seleccion->refresh();
    }

    /**
     * Pasa la sesión al estado que corresponde a lo que se hizo.
     *
     * Confirmar deja la sesión en `pendiente_seleccion`: ya eligió, lo que falta
     * es que el estudio entregue. Marcar sin confirmar la deja en `realizada`,
     * porque tomar las fotos ya pasó y la elección todavía no.
     */
    private function acompanarElEstadoDeLaSesion(Sesion $sesion, Seleccion $seleccion): void
    {
        if ($sesion->estado === EstadoSesion::Entregada) {
            // Volver a elegir sobre una sesión entregada es una decisión de
            // negocio, no un efecto de registrar una selección. Se corta acá.
            return;
        }

        $destino = $seleccion->estaConfirmada()
            ? EstadoSesion::PendienteSeleccion
            : EstadoSesion::Realizada;

        if ($sesion->estado === $destino) {
            return;
        }

        $this->gestor->moverA($sesion, $destino);
    }

    /**
     * Cuántas fotos hay, y cuántas se eligieron, para la galería.
     *
     * @return array{total: int, elegidas: int}
     */
    public function conteo(Sesion $sesion): array
    {
        return [
            'total' => $sesion->fotos()->count(),
            'elegidas' => $sesion->fotos()->whereHas(
                'detallesDeSeleccion',
                static fn ($consulta) => $consulta->where('elegida', true)
            )->count(),
        ];
    }

    /**
     * Las fotos de la sesión, con la marca de cuáles están elegidas.
     *
     * @return \Illuminate\Support\Collection<int, Foto>
     */
    public function galeria(Sesion $sesion)
    {
        $elegidas = $sesion->seleccion()
            ? $sesion->seleccion()->detalles()->where('elegida', true)->pluck('foto_id')->all()
            : [];

        return $sesion->fotos()->orderBy('nombre_archivo')->get()->map(
            static function (Foto $foto) use ($elegidas): Foto {
                $foto->setAttribute('elegida', in_array((string) $foto->id, $elegidas, true));

                return $foto;
            }
        );
    }
}