<?php

declare(strict_types=1);

namespace App\Services\Sesiones;

use App\Enums\EstadoSesion;
use App\Models\Sesion;
use DomainException;

/**
 * Las transiciones de estado de una sesión, y la regla que las gobierna.
 *
 * LA REGLA: NO SE ENTREGA SIN SELECCIÓN CONFIRMADA
 * -------------------------------------------------
 * `entregada` exige una selección `confirmada`. No es una recomendación: es la
 * condición para poder escribir el estado, y si falta, la operación se rechaza
 * con un error de dominio que se muestra a la persona.
 *
 * La razón es plata. Imprimir es un encargo a un laboratorio externo, se paga por
 * adelantado y no se devuelve. Si se marca una sesión como entregada sin que
 * nadie haya elegido, lo que se imprime son fotos que nadie quiere, y esa plata
 * no vuelve.
 *
 * POR QUÉ `PENDIENTE_SELECCION` NO ES UN ERROR
 * --------------------------------------------
 * Es el estado NORMAL de una sesión que se tomó y en la que la persona todavía
 * no eligió. El sistema lo muestra, lo filtra y lo cuenta, y no dispara ningún
 * error. Tratarlo como falla haría que el equipo aprendiera a ignorar la
 * pantalla, que es como se pierde una fecha prometida.
 *
 * POR QUÉ NO SE TOCA LA FECHA REALIZADA AL VOLVER A AGENDAR
 * -------------------------------------------------------
 * `fecha_realizada` es un hecho. Volver a agendar una sesión por lluvia no
 * borra que se tomó el día anterior: la deja en su columna, y se cambia la
 * prometida. Ver docs/02.
 */
class GestorDeEstadoDeSesion
{
    /**
     * Los estados a los que se puede pasar desde cada uno.
     *
     * Es una lista CERRADA y se declara acá, no en el enum: una transición que no
     * está acá es una transición que nadie ha pensado.
     *
     * @var array<string, list<string>>
     */
    private const TRANSICIONES = [
        'agendada' => ['realizada', 'pendiente_seleccion'],
        'realizada' => ['pendiente_seleccion', 'agendada'],
        'pendiente_seleccion' => ['entregada', 'realizada'],
        'entregada' => ['pendiente_seleccion'],
    ];

    /**
     * Aplica el cambio, comprobando las dos cosas que importan.
     *
     * @throws DomainException si la transición no existe o falta la selección
     */
    public function moverA(Sesion $sesion, EstadoSesion $destino, ?\DateTimeInterface $realizada = null): Sesion
    {
        $origen = $sesion->estado;

        if (! $this->transicionExiste($origen, $destino)) {
            throw new DomainException(sprintf(
                'Una sesión %s no puede pasar a %s.',
                $origen->etiqueta(),
                $destino->etiqueta()
            ));
        }

        if ($destino === EstadoSesion::Realizada && $realizada === null) {
            throw new DomainException('Hay que decir qué día se tomó la sesión para marcarla como realizada.');
        }

        // La regla. Se comprueba acá y no en la pantalla, porque entre que se
        // carga la pantalla y se aprieta el botón la selección puede haber
        // cambiado, y una comprobación en el navegador no vale.
        if ($destino === EstadoSesion::Entregada) {
            $this->exigirSeleccionConfirmada($sesion);
        }

        $sesion->estado = $destino;

        if ($realizada !== null) {
            $sesion->fecha_realizada = $realizada;
        }

        $sesion->save();

        return $sesion;
    }

    /**
     * ¿La transición está permitida por la tabla?
     */
    public function transicionExiste(EstadoSesion $origen, EstadoSesion $destino): bool
    {
        $permitidas = self::TRANSICIONES[$origen->value] ?? [];

        return in_array($destino->value, $permitidas, true);
    }

    /**
     * La condición de entrega, sola y con su mensaje.
     *
     * Se expone pública porque la pantalla la necesita para poder explicar por
     * qué el botón está deshabilitado, en vez de dejar a la persona apretando y
     * leyendo un error.
     *
     * @throws DomainException
     */
    public function exigirSeleccionConfirmada(Sesion $sesion): void
    {
        if ($sesion->tieneSeleccionConfirmada()) {
            return;
        }

        $tieneSeleccion = $sesion->seleccion()->exists();

        $motivo = $tieneSeleccion
            ? 'La selección existe pero no está confirmada.'
            : 'No hay ninguna selección registrada.';

        throw new DomainException(
            'No se puede marcar la sesión como entregada: ' . $motivo
            . ' Hasta que la persona elija no hay nada que imprimir, y lo que se imprime '
            . 'se paga por adelantado a un laboratorio.'
        );
    }

    /**
     * Por qué la sesión no se puede entregar todavía, o `null` si sí se puede.
     *
     * Es lo que la pantalla usa para el botón. No reemplaza la comprobación del
     * servidor: es la misma comprobación, mostrada antes.
     */
    public function motivoDeNoPoderEntregar(Sesion $sesion): ?string
    {
        try {
            $this->exigirSeleccionConfirmada($sesion);

            return null;
        } catch (DomainException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Las transiciones posibles desde el estado actual, para pintar los botones.
     *
     * @return list<EstadoSesion>
     */
    public function posiblesDesde(EstadoSesion $origen): array
    {
        $permitidas = self::TRANSICIONES[$origen->value] ?? [];

        return array_values(array_map(
            static fn (string $valor): EstadoSesion => EstadoSesion::from($valor),
            $permitidas
        ));
    }
}