<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fija el estudio de la petición para que la Row Level Security sepa de quién
 * son las filas.
 *
 * LA REGLA QUE NO SE ROMPE
 * ------------------------
 * La marca se pone con `set_config('app.tenant_id', ..., true)`. El tercer
 * `true` es `is_local`: la marca vive SOLO dentro de la transacción y
 * desaparece al terminar.
 *
 * Sin ese `true`, `set_config` escribe en la sesión de PostgreSQL, que sobrevive
 * al `COMMIT`. Con una conexión reutilizable del pool, la petición siguiente
 * hereda el estudio anterior y lee datos de otro estudio sin ningún aviso. Es el
 * error más grave posible en este sistema y el más fácil de introducir por
 * descuido.
 *
 * El patrón viene de `apps/panaderia` y `apps/almacen`. Ver
 * docs/03-MULTITENANCY-Y-ROLES.md.
 */
class ContextoEstudio
{
    /**
     * El nombre de la marca en PostgreSQL.
     *
     * Va como constante y no como texto suelto en el código: la usan el
     * servicio, los gatillos y las pruebas, y si los tres escribieran el nombre
     * a mano, una diferencia de una letra dejaría el aislamiento abierto sin que
     * nadie lo notara.
     */
    public const VARIABLE = 'app.tenant_id';

    public function ejecutarAislado(string $estudioId, callable $operacion): mixed
    {
        /** @var ConnectionInterface $conexion */
        $conexion = DB::connection();

        // Verificar que el estudio existe ANTES de abrir la transacción evita
        // abrirla para nada cuando el identificador vino manipulado.
        $existe = $conexion->table('estudios')->where('id', $estudioId)->exists();

        if (! $existe) {
            abort(404, 'No encontrado.');
        }

        return $conexion->transaction(function () use ($conexion, $estudioId, $operacion): mixed {
            //(is_local = true) es lo que acota la marca a esta transacción.
            $conexion->statement(
                'SELECT set_config(?, ?, true)',
                [self::VARIABLE, $estudioId]
            );

            return $operacion();
        });
    }

    /**
     * Lo mismo, pero sin comprobar que el estudio exista.
     *
     * Lo usan los comandos de consola y los trabajos de cola, que parten de un
     * estudio que ya viene declarado y que fallar con un 404 en una tarea de
     * fondo dejaría el trabajo a medias sin que nadie lo viera.
     */
    public function ejecutarPara(string $estudioId, callable $operacion): mixed
    {
        /** @var ConnectionInterface $conexion */
        $conexion = DB::connection();

        return $conexion->transaction(function () use ($conexion, $estudioId, $operacion): mixed {
            $conexion->statement(
                'SELECT set_config(?, ?, true)',
                [self::VARIABLE, $estudioId]
            );

            return $operacion();
        });
    }
}