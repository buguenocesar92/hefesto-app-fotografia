<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Auth\DescubridorOidc;
use App\Services\Auth\ProveedorOidcAuthentik;
use App\Services\Conservacion\VerificadorDeConservacion;
use App\Services\Permisos\CapacidadesDeUsuario;
use Illuminate\Support\ServiceProvider;

/**
 * Enlaza los servicios con sus escalares de configuración.
 *
 * POR QUÉ LOS PERMISOS NO SE REGISTRAN ACÁ
 * ---------------------------------------
 * Porque un `Gate::before` es un atajo. Ocultar un botón no impide que alguien
 * llame a la API directamente, así que cada ruta vuelve a preguntar a su `Policy`.
 * Lo único que se registra son servicios: los que antes se construían a mano en
 * los controladores y quedaban con configuración escrita en el constructor.
 * Ver docs/03.
 *
 * TODA LA CONFIGURACIÓN VIENE DE `config/`
 * ----------------------------------------
 * No hay un solo valor de Authentik escrito en el código. Si alguien cambia el
 * emisor, cambia el `.env` y no toca PHP. Ver docs/05.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DescubridorOidc::class, static fn (): DescubridorOidc => new DescubridorOidc(
            emisor: (string) config('authentik.emisor'),
            clienteId: (string) config('authentik.cliente_id'),
            clienteSecreto: (string) config('authentik.cliente_secreto'),
            redireccionUri: (string) config('authentik.redireccion_uri'),
        ));

        $this->app->singleton(ProveedorOidcAuthentik::class, static fn ($app): ProveedorOidcAuthentik => new ProveedorOidcAuthentik(
            descubridor: $app->make(DescubridorOidc::class),
            emisor: (string) config('authentik.emisor'),
            clienteId: (string) config('authentik.cliente_id'),
            equiposPorRol: (array) config('authentik.equipo_por_rol', []),
            prefijoEstudio: (string) config('authentik.prefijo_estudio', 'estudio'),
        ));

        // Esta clase no tiene dependencias: se puede resolver en cualquier
        // momento, y un `singleton` es solo una promesa de que es barato.
        $this->app->singleton(CapacidadesDeUsuario::class);
        $this->app->singleton(VerificadorDeConservacion::class);
    }

    public function boot(): void
    {
        // Nada que bootear: los servicios no tocan la base al construirse, y por
        // eso se pueden pedir desde el middleware de una ruta que todavía no
        // tiene contexto de estudio.
    }
}