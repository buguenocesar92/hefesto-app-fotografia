<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\AccionAccesoArchivo;
use App\Models\AccesoArchivo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Anota quién abrió qué original, y si se le dejó.
 *
 * POR QUÉ UNA CONEXIÓN APARTE
 * --------------------------
 * La petición a la que se le deniega el acceso a un archivo termina revertida. Si
 * el registro del intento fuera por la misma conexión, la reversa se llevaría por
 * delante justo el registro que más importa: el intento denegado.
 *
 * Por eso `config/database.php` declara una conexión `auditoria` con su propio
 * juego de variables. Por omisión apunta a la misma base, y se puede separar
 * físicamente con `DB_AUDITORIA_*`, que es una de las separaciones que suele
 * pedir una auditoría externa. Ver docs/07.
 *
 * EL INTENTO DENEGADO TAMBIÉN SE ESCRIBE
 * -------------------------------------
 * Porque es el registro que hay que poder revisar. Si solo se anotara lo permitido,
 * alguien probando accesos quedaría sin rastro.
 *
 * SI LA ESCRITURA FALLA, NO SE ROMPE LA PETICIÓN
 * ---------------------------------------------
 * Un acceso denegado no puede terminar en un error 500: la respuesta correcta es
 * el 404 de la Policy, y la anotación es un efecto secundario. Si el registro no
 * se puede escribir, se avisa en el registro del servidor y se sigue. Un sistema
 * que no se puede usar porque la bitácora está caída es un sistema sin bitácora.
 */
class AuditorDeAcceso
{
    public function __construct(private readonly string $conexion = 'auditoria') {}

    /**
     * @param  array<string, mixed>  $detalle
     */
    public function anotar(
        ?string $estudioId,
        ?string $usuarioId,
        AccionAccesoArchivo $accion,
        bool $permitido,
        ?string $sesionId = null,
        ?string $conservacionId = null,
        ?string $ip = null,
        array $detalle = [],
    ): void {
        // Sin estudio no hay a qué atribuir el acceso, y una fila sin `tenant_id`
        // la rechazaría la Row Level Security de todas formas. Es la situación
        // de un ingreso fallido, que no es un acceso a un archivo.
        if ($estudioId === null) {
            return;
        }

        try {
            DB::connection($this->conexion)->table('accesos_archivo')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $estudioId,
                'usuario_id' => $usuarioId,
                'sesion_id' => $sesionId,
                'conservacion_id' => $conservacionId,
                'accion' => $accion->value,
                'permitido' => $permitido,
                'ip' => $ip !== null ? substr($ip, 0, 45) : null,
                'fecha' => now(),
                'detalle' => $detalle === [] ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Se avisa y se sigue. Ver la nota del encabezado.
            Log::error('no se pudo anotar el acceso a un archivo', [
                'accion' => $accion->value,
                'permitido' => $permitido,
                'estudio' => $estudioId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Los intentos sobre una sesión, para la pantalla de auditoría.
     *
     * @return \Illuminate\Support\Collection<int, AccesoArchivo>
     */
    public function paraSesion(string $estudioId, string $sesionId)
    {
        return AccesoArchivo::query()
            ->where('sesion_id', $sesionId)
            ->latest('fecha')
            ->limit(200)
            ->get();
    }
}