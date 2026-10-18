<?php

declare(strict_types=1);

use App\Http\Controllers\Agenda\AgendaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Catalogo\CatalogoController;
use App\Http\Controllers\Clientes\ClienteController;
use App\Http\Controllers\Conservacion\ConservacionController;
use App\Http\Controllers\Contratos\ContratoController;
use App\Http\Controllers\Cobranza\SaldoController;
use App\Http\Controllers\Laboratorio\PedidoLaboratorioController;
use App\Http\Controllers\Panel\PanelController;
use App\Http\Controllers\Seleccion\SeleccionController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Rutas de la API de Fotografía
|-------------------------------------------------------------------------------
|
| Todas cuelgan del grupo `web` de Laravel (ver bootstrap/app.php) y del prefijo
| `/api/v1`, que se aplica acá y no en el esqueleto: queda a la vista que TODA la
| superficie de la API está versionada en una sola línea.
|
| Estas rutas las consume el BFF de Nuxt (web/server/api), que reenvía las
| cookies del navegador. Ver docs/01 y docs/09.
|
| EL CONTROL FINO NO ESTÁ ACÁ
| --------------------------
| Las rutas declaran QUÉ recurso se toca y CON QUÉ MIDDLEWARE. Quién puede
| tocarlo lo decide `App\Policies`. El orden importa y es el siguiente:
|
|   1. `auth`                confirma la sesión
|   2. `archivo.auditado`    anota el intento de abrir un original
|   3. `administracion`      corta las rutas de administración
|   4. Policy                permiso fino, con 404 si rechaza
|
| Un rechazo de Policy responde 404 y no 403: un 403 confirmaría que el recurso
| existe. Ver bootstrap/app.php y docs/03.
|
*/
Route::prefix('api/v1')->name('api.v1.')->group(function (): void {

    /*
    |---------------------------------------------------------------------------
    | Autenticación (OIDC contra Authentik)
    |---------------------------------------------------------------------------
    | Las dos primeras rutas corren SIN sesión y SIN contexto de estudio. Por eso
    | leen `vw_estudios_publicas`, que expone solo slug, nombre y activo.
    */
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::get('estudios', [AuthController::class, 'estudios'])->name('estudios');
        Route::get('entrar/{slug}', [AuthController::class, 'entrar'])->name('entrar');
        Route::get('callback', [AuthController::class, 'callback'])->name('callback');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('salir', [AuthController::class, 'salir'])->name('salir');

        Route::middleware('auth')->group(function (): void {
            Route::get('usuario', [AuthController::class, 'usuario'])->name('usuario');
        });
    });

    Route::middleware('auth')->group(function (): void {

        /*
        |-----------------------------------------------------------------------
        | Panel: lo primero que se ve
        |-----------------------------------------------------------------------
        */
        Route::get('panel', PanelController::class)->name('panel');

        /*
        |-----------------------------------------------------------------------
        | Agenda de sesiones
        |-----------------------------------------------------------------------
        | La agenda se lee por fecha y devuelve poco: título, tipo, quién atiende.
        | El detalle de una sesión, con su selección y su conservación, está en
        | `sesiones/{id}`. Ver docs/09.
        */
        Route::get('agenda', [AgendaController::class, 'agenda'])->name('agenda.index');
        Route::get('agenda/tipos', [AgendaController::class, 'tipos'])->name('agenda.tipos');
        Route::get('agenda/fotografos', [AgendaController::class, 'fotografos'])->name('agenda.fotografos');

        Route::get('sesiones/{sesion}', [AgendaController::class, 'show'])->name('sesiones.show');
        Route::post('sesiones', [AgendaController::class, 'store'])->name('sesiones.store');

        // Registrar cómo terminó de verdad, con la fecha en que se tomó. No es
        // editar la sesión: es escribir lo que pasó. Por eso es otra ruta.
        Route::post('sesiones/{sesion}/realizada', [AgendaController::class, 'registrarRealizacion'])
            ->name('sesiones.realizada');

        /*
        |-----------------------------------------------------------------------
        | SELECCIÓN DE FOTOS
        |-----------------------------------------------------------------------
        | Esta pantalla espera a la persona, y por eso tiene su propio estado y su
        | propia ruta de entrega. Marcar entregada NO es lo mismo que registrar la
        | selección: la selección decide QUÉ se imprime, la entrega declara que
        | terminó. Ver docs/03.
        */
        Route::get('sesiones/{sesion}/seleccion', [SeleccionController::class, 'index'])
            ->name('seleccion.index');
        Route::post('sesiones/{sesion}/seleccion', [SeleccionController::class, 'store'])
            ->name('seleccion.store');
        Route::post('sesiones/{sesion}/entregar', [SeleccionController::class, 'entregar'])
            ->name('seleccion.entregar');

        /*
        |-----------------------------------------------------------------------
        | Clientes
        |-----------------------------------------------------------------------
        | `destroy` DESACTIVA la ficha, no la borra: una persona que contrata hoy
        | puede volver en tres años y su historial tiene que seguir ahí. Por eso
        | la ruta es un POST y el verbo HTTP no dice "borrar". Ver docs/02.
        */
        Route::get('clientes', [ClienteController::class, 'index'])->name('clientes.index');
        Route::post('clientes', [ClienteController::class, 'store'])->name('clientes.store');
        Route::get('clientes/{cliente}', [ClienteController::class, 'show'])->name('clientes.show');
        Route::put('clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
        Route::post('clientes/{cliente}/desactivar', [ClienteController::class, 'destroy'])
            ->name('clientes.desactivar');

        /*
        |-----------------------------------------------------------------------
        | Contratos, paquetes y entregas
        |-----------------------------------------------------------------------
        | Registrar una entrega es `POST` y no `PATCH` a propósito: es un hecho
        | nuevo, y una pantalla que guardara dos veces sumaría dos veces. Y una
        | entrega parcial NO cierra el contrato. Ver docs/02.
        */
        Route::get('contratos', [ContratoController::class, 'index'])->name('contratos.index');
        Route::post('contratos', [ContratoController::class, 'store'])->name('contratos.store');
        Route::get('contratos/{contrato}', [ContratoController::class, 'show'])->name('contratos.show');
        Route::post('contratos/{contrato}/anular', [ContratoController::class, 'anular'])
            ->name('contratos.anular');
        Route::post('contratos/{contrato}/entregas', [ContratoController::class, 'registrarEntrega'])
            ->name('contratos.entregas.store');

        /*
        |-----------------------------------------------------------------------
        | Saldos y abonos
        |-----------------------------------------------------------------------
        | La respuesta es un DESGLOSE, no un número. Ver
        | App\Services\Cobranza\DesgloseDeSaldo.
        */
        Route::get('saldos', [SaldoController::class, 'index'])->name('saldos.index');
        Route::get('saldos/metodos', [SaldoController::class, 'metodos'])->name('saldos.metodos');
        Route::get('saldos/{contrato}', [SaldoController::class, 'show'])->name('saldos.show');
        Route::post('saldos/{contrato}/abonos', [SaldoController::class, 'registrar'])
            ->name('saldos.abonos.store');

        /*
        |-----------------------------------------------------------------------
        | Impresión por subcontrato
        |-----------------------------------------------------------------------
        | El contenido del pedido se congela al enviarlo: después de eso hay un
        //  trabajo en curso en otro lado. Ver SeguimientoDePedidos.
        */
        Route::get('laboratorio', [PedidoLaboratorioController::class, 'index'])
            ->name('laboratorio.index');
        Route::get('laboratorio/directorios', [PedidoLaboratorioController::class, 'laboratorios'])
            ->name('laboratorio.laboratorios');
        Route::post('laboratorio', [PedidoLaboratorioController::class, 'store'])
            ->name('laboratorio.store');
        Route::get('laboratorio/{pedido}', [PedidoLaboratorioController::class, 'show'])
            ->name('laboratorio.show');
        Route::post('laboratorio/{pedido}/enviar', [PedidoLaboratorioController::class, 'enviar'])
            ->name('laboratorio.enviar');
        Route::post('laboratorio/{pedido}/recibir', [PedidoLaboratorioController::class, 'recibir'])
            ->name('laboratorio.recibir');

        /*
        |-----------------------------------------------------------------------
        | CONSERVACIÓN DE LOS ARCHIVOS
        |-----------------------------------------------------------------------
        | La lista la ven las tres personas: saber en qué disco están las fotos de
        | una sesión es lo primero que hay que poder contestar cuando alguien
        | pregunta por una foto. Lo que es de administración es cambiar el ESTADO,
        | porque eso es una afirmación sobre la custodia. Ver docs/08.
        */
        Route::get('conservaciones', [ConservacionController::class, 'index'])
            ->name('conservaciones.index');
        Route::post('conservaciones', [ConservacionController::class, 'store'])
            ->name('conservaciones.store');
        Route::get('conservaciones/en-riesgo', [ConservacionController::class, 'sesionesEnRiesgo'])
            ->name('conservaciones.en-riesgo');
        Route::get('conservaciones/{conservacion}', [ConservacionController::class, 'historial'])
            ->name('conservaciones.historial');
        Route::patch('conservaciones/{conservacion}', [ConservacionController::class, 'update'])
            ->name('conservaciones.update');
        Route::post('conservaciones/{conservacion}/verificar', [ConservacionController::class, 'verificar'])
            ->name('conservaciones.verificar');

        /*
        |-----------------------------------------------------------------------
        | ABRIR UN ORIGINAL: TODO ESTO VA AUDITADO
        |-----------------------------------------------------------------------
        | Cada intento queda en `accesos_archivo`, permitido o denegado, con la
        | dirección desde la que vino. El middleware anota y después corta, para
        | que el intento denegado también quede anotado. Ver docs/07.
        */
        Route::middleware('archivo.auditado:descarga')->group(function (): void {
            Route::get('sesiones/{sesion}/archivos/{foto}', [\App\Http\Controllers\Archivos\FotoArchivoController::class, 'show'])
                ->name('archivos.show');
        });

        /*
        |-----------------------------------------------------------------------
        | ADMINISTRACIÓN DEL ESTUDIO
        |-----------------------------------------------------------------------
        | Estas rutas cuelgan del middleware `administracion`, que corta para quien
        | no administra ANTES de llegar a la Policy. Son dos capas: el middleware
        | es la puerta, la Policy es la regla. Ver bootstrap/app.php.
        */
        Route::middleware('administracion')->group(function (): void {

            Route::get('administracion/paquetes', [CatalogoController::class, 'paquetes'])
                ->name('administracion.paquetes.index');
            Route::post('administracion/paquetes', [CatalogoController::class, 'guardarPaquete'])
                ->name('administracion.paquetes.store');

            Route::get('administracion/laboratorios', [CatalogoController::class, 'laboratorios'])
                ->name('administracion.laboratorios.index');
            Route::post('administracion/laboratorios', [CatalogoController::class, 'guardarLaboratorio'])
                ->name('administracion.laboratorios.store');

            Route::get('administracion/cuentas', [CatalogoController::class, 'usuarios'])
                ->name('administracion.cuentas.index');
        });
    });
});