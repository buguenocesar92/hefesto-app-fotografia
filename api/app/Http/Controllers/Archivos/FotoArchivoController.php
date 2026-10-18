<?php

declare(strict_types=1);

use App\Models\Foto;
use App\Models\Sesion;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Abrir un original de una sesión.
 *
 * POR QUÉ ESTA RUTA NO DEVUELVE EL ARCHIVO
 * ---------------------------------------
 * En el equipo donde se escribió esta aplicación no se pudo verificar cómo se
 * montan los medios declarados en `conservaciones`: un disco local, una red de
 * área local y almacenamiento en la nube no se montan igual, y no hay forma de
 * verificar aquí cuál de los tres usa un estudio.
 *
 * Inventar una ruta de lectura sería exactamente el tipo de dato que no existe.
 * Por eso lo que hay es la FICHA del original: nombre, tamaño, huella SHA-256,
 * dónde está y cuándo se vio por última vez. Y eso sí se puede comprobar.
 *
 * LO QUE SÍ HACE Y ES REAL
 * -----------------------
 *  - Comprueba la Policy de la sesión.
 *  - Deja el intento anotado en `accesos_archivo`, permitido o denegado.
 *  - Devuelve los datos que constan en el sistema, y NUNCA una ruta de sistema
 *    de archivos.
 *
 * CUANDO SE MONTE EL MEDIO
 * -----------------------
 * Esta clase es el lugar donde va la lectura real: un único archivo, y con el
 * resto de la aplicación sin enterarse. La forma de montarlo depende del estudio
 * y hay que decidirla con él. Ver docs/08.
 *
 * @see BinaryFileResponse reservado para cuando ese día llegue
 */
class FotoArchivoController extends Controller
{
    public function show(Request $peticion, Sesion $sesion, Foto $foto): JsonResponse
    {
        $usuario = $this->usuarioObligatorio($peticion);

        // 404 si no es del estudio: un 403 confirmaría que la foto existe.
        if (! $usuario->esDelMismoEstudio($sesion) || ! $usuario->esDelMismoEstudio($foto)) {
            return response()->json(['mensaje' => 'No encontrado.'], 404);
        }

        $this->authorize('verArchivos', $sesion);

        // La bitácora se consulta por sesión, y no por foto: `accesos_archivo`
        // registra quién abrió qué sesión, con qué acción. Ver la migración que
        // crea la tabla.
        $ultimoAcceso = \App\Models\AccesoArchivo::query()
            ->where('sesion_id', $sesion->id)
            ->where('accion', 'descarga')
            ->where('permitido', true)
            ->latest('fecha')
            ->first();

        return response()->json([
            'foto' => [
                'id' => (string) $foto->id,
                'sesion_id' => (string) $foto->sesion_id,
                'nombre_archivo' => (string) $foto->nombre_archivo,
                'extension' => $foto->extension,
                'bytes' => $foto->bytes,
                'huella_sha256' => $foto->huella_sha256,
                'ruta_relativa' => $foto->ruta_relativa,
                'conservacion_id' => $sesion->conservacion_id,
                'conservacion' => $sesion->conservacion?->nombre,
                'estado_conservacion' => $sesion->conservacion?->estado->etiqueta(),
            ],

            'ultimo_acceso' => $ultimoAcceso?->fecha?->toIso8601String(),

            // Declarado en la respuesta, para que la interfaz no ofrezca un botón
            // de descarga que no existe todavía.
            'lectura_del_archivo_implementada' => false,
            'nota' => 'Este sistema todavía no devuelve el contenido del archivo. '
                . 'Devuelve la ficha del original y su huella. Ver docs/08.',
        ]);
    }
}