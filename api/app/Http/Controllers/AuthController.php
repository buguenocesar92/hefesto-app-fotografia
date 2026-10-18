<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\Auth\ProveedorOidcAuthentik;
use App\Services\Permisos\CapacidadesDeUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Autenticación contra Authentik (OIDC).
 *
 * ANTES DEL INGRESO, SIN ESTUDIO
 * -----------------------------
 * Las peticiones de esta pantalla son las únicas que corren sin contexto de
 * estudio. Por eso no pueden tocar ninguna tabla de negocio: si lo hicieran,
 * `public.app_tenant_id()` lanzaría excepción. Para ubicar el estudio se lee la
 * vista `vw_estudios_publicas`, que expone SOLO `slug`, `nombre` y `activo`.
 *
 * LA VISTA SE USA, NO SE DECLARA
 * ------------------------------
 * `estudios()` y `entrar()` leen de la vista. No de la tabla. Esa es la diferencia
 * entre prometer una protección y tenerla: si alguien le agrega una columna a la
 * vista, el selector de ingreso empieza a exponer ese dato, y el comando
 * `fotografia:verificar-aislamiento` lo detecta contando las columnas.
 *
 * Ver la migración de la vista y docs/03.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly ProveedorOidcAuthentik $proveedor,
        private readonly CapacidadesDeUsuario $capacidades,
    ) {}

    /**
     * Los estudios activos, para el selector de la pantalla de ingreso.
     *
     * Se lee la VISTA, no la tabla: son tres columnas a propósito.
     */
    public function estudios(): JsonResponse
    {
        return response()->json([
            'estudios' => DB::table('vw_estudios_publicas')
                ->orderBy('nombre')
                ->get(['slug', 'nombre']),
        ]);
    }

    /**
     * Redirige al proveedor de identidad para un estudio concreto.
     *
     * El estudio se valida contra la vista antes de redirigir: mandar a Authentik
     * un estudio que no existe produce un error de vuelta que nadie entiende.
     */
    public function entrar(Request $peticion, string $slug): JsonResponse
    {
        $existe = DB::table('vw_estudios_publicas')->where('slug', $slug)->first();

        if ($existe === null) {
            return response()->json([
                'mensaje' => 'No hay ningún estudio activo con ese identificador.',
            ], 404);
        }

        return response()->json([
            'redirigir_a' => $this->proveedor->urlDeAutorizacion($peticion, $slug),
        ]);
    }

    /**
     * Callback del proveedor: verifica el token y abre la sesión.
     */
    public function callback(Request $peticion): RedirectResponse
    {
        $estudioSlug = (string) session('oidc.estudio', '');

        if ($estudioSlug === '') {
            return redirect('/entrar?error=El ingreso no indicó a qué estudio pertenece.');
        }

        // El estado anti falsificación se compara antes de tocar nada.
        $estadoEsperado = (string) session('oidc.estado', '');
        $estadoRecibido = (string) $peticion->query('state', '');

        if ($estadoEsperado === '' || ! hash_equals($estadoEsperado, $estadoRecibido)) {
            return redirect('/entrar?error=La solicitud de ingreso no es válida. Intente de nuevo.');
        }

        $peticion->session()->forget(['oidc.estado', 'oidc.estudio']);

        try {
            $claims = $this->proveedor->validarCallback($peticion);

            $usuario = $this->proveedor->usuarioDesdeClaims($claims, $estudioSlug);
        } catch (\Throwable $e) {
            report($e);

            return redirect('/entrar?error=' . urlencode('No se pudo completar el ingreso.'));
        }

        if ($usuario === null) {
            return redirect('/entrar?error=' . urlencode(
                'La cuenta no está habilitada en este estudio, o el estudio está inactivo.'
            ));
        }

        Auth::guard('web')->login($usuario, true);
        $peticion->session()->regenerate();

        return redirect('/');
    }

    /** Cierre de sesión. */
    public function logout(Request $peticion): RedirectResponse
    {
        Auth::guard('web')->logout();

        $peticion->session()->invalidate();
        $peticion->session()->regenerateToken();

        return redirect('/entrar');
    }

    /**
     * Quién entró, con qué rol y qué puede hacer.
     *
     * Las capacidades vienen del servidor: la interfaz las usa para esconder
     * botones, pero no son una medida de seguridad. Ver docs/03.
     */
    public function usuario(Request $peticion): JsonResponse
    {
        $usuario = $peticion->user();

        if (! $usuario instanceof Usuario) {
            return response()->json(['mensaje' => 'No hay sesión abierta.'], 401);
        }

        $estudio = $usuario->tenant_id !== null
            ? \App\Models\Estudio::query()->find($usuario->tenant_id)
            : null;

        return response()->json([
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'correo' => $usuario->correo,
            'rol' => $usuario->rol->value,
            'rol_etiqueta' => $usuario->rol->etiqueta(),
            'puede_ver_archivos' => $usuario->puede_ver_archivos,
            'estudio' => $estudio === null ? null : [
                'id' => $estudio->id,
                'nombre' => $estudio->nombre,
                'nombre_corto' => $estudio->nombre_corto,
            ],
            'capacidades' => $this->capacidades->de($usuario),
        ]);
    }

    /**
     * Cierra de sesión desde la interfaz, para el cliente de Nuxt.
     */
    public function salir(): JsonResponse
    {
        Auth::guard('web')->logout();

        session()->invalidate();
        session()->regenerateToken();

        return response()->json(['mensaje' => 'Sesión cerrada.']);
    }
}