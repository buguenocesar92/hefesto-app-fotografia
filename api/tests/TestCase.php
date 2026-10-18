<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\EstadoSesion;
use App\Enums\MetodoAbono;
use App\Enums\RolEstudio;
use App\Enums\TipoLineaContrato;
use App\Enums\TipoSesion;
use App\Models\Abono;
use App\Models\Cliente;
use App\Models\Conservacion;
use App\Models\Contrato;
use App\Models\Estudio;
use App\Models\Foto;
use App\Models\LineaContrato;
use App\Models\Sesion;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoEstudio;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base de las pruebas de Fotografía.
 *
 * POR QUÉ TODAS LAS PRUEBAS NECESITAN `dentroDe`
 * ----------------------------------------------
 * Las tablas de negocio tienen Row Level Security FORZADO, para que las políticas
 * apliquen también al dueño de la tabla (que es el rol con el que se conecta
 * Laravel). Consecuencia: si una prueba crea filas sin declarar el estudio al que
 * pertenecen, la base las rechaza.
 *
 * Eso no es un estorbo para probar, es parte de lo que se prueba. `dentroDe` es el
 * mismo camino que usa la petición real: una transacción con el contexto fijado y
 * alcance de transacción.
 *
 * POSTGRESQL Y NO SQLITE
 * ----------------------
 * En SQLite estas pruebas no comprobarían nada, porque no hay RLS: pasarían en
 * verde sobre un sistema sin aislamiento. Por eso `phpunit.xml` fija
 * `DB_CONNECTION=pgsql` y una base de pruebas PostgreSQL. Ver docs/10.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Ejecuta una operación dentro del contexto de un estudio.
     *
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    protected function dentroDe(Usuario|Estudio $contexto, Closure $operacion): mixed
    {
        $estudioId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return app(ContextoEstudio::class)->ejecutarAislado($estudioId, $operacion);
    }

    /**
     * Crea un estudio.
     *
     * `estudios` no tiene RLS a propósito (se consulta antes de que exista
     * `app.tenant_id`), así que se puede crear sin contexto.
     */
    protected function crearEstudio(string $slug = 'estudio-de-prueba'): Estudio
    {
        return Estudio::query()->create([
            'slug' => $slug,
            'nombre' => 'Estudio de prueba',
            'nombre_corto' => 'Prueba',
            'activo' => true,
        ]);
    }

    /**
     * Crea una cuenta.
     */
    protected function crearUsuario(
        Usuario|Estudio $contexto,
        RolEstudio $rol = RolEstudio::Recepcion,
        bool $conArchivos = false,
    ): Usuario {
        $estudioId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, fn (): Usuario => Usuario::query()->create([
            'tenant_id' => $estudioId,
            'nombre' => 'Persona de prueba',
            'correo' => 'persona' . substr(md5($estudioId . $rol->value), 0, 8) . '@ejemplo.cl',
            'rol' => $rol->value,
            'puede_ver_archivos' => $conArchivos,
            'activo' => true,
        ]));
    }

    protected function crearCliente(Usuario|Estudio $contexto, string $nombre = 'Persona'): Cliente
    {
        $estudioId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, fn (): Cliente => Cliente::query()->create([
            'tenant_id' => $estudioId,
            'nombre' => $nombre,
            'apellido' => 'De Prueba',
            'rut' => null,
            'telefono' => '+56 9 0000 0000',
            'activo' => true,
        ]));
    }

    /**
     * Crea un contrato con una línea.
     *
     * Los precios NO tienen valor por omisión en el software: se declaran en el
     * catálogo y se copian a las líneas. Acá se pasan como argumentos porque una
     * prueba necesita cifras concretas para poder comprobar una cuenta.
     */
    protected function crearContrato(
        Usuario|Estudio $contexto,
        Cliente $cliente,
        int $precioTotal = 200000,
        array $lineas = [],
    ): Contrato {
        $estudioId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        if ($lineas === []) {
            $lineas = [[
                'tipo' => TipoLineaContrato::FotoEntregada->value,
                'concepto' => 'Fotos tratadas',
                'cantidad_pactada' => 100,
                'precio_unitario' => 2000,
            ]];
        }

        return $this->dentroDe($contexto, function () use ($estudioId, $cliente, $precioTotal, $lineas): Contrato {
            $contrato = Contrato::query()->create([
                'tenant_id' => $estudioId,
                'cliente_id' => $cliente->id,
                'codigo' => 'C-' . substr(md5((string) $cliente->id), 0, 8),
                'fecha_contrato' => today()->toDateString(),
                'precio_total' => $precioTotal,
                'estado' => 'activo',
            ]);

            foreach ($lineas as $linea) {
                $contrato->lineas()->create([
                    'tenant_id' => $estudioId,
                    'tipo' => $linea['tipo'],
                    'concepto' => $linea['concepto'],
                    'unidad_minutos' => $linea['unidad_minutos'] ?? null,
                    'cantidad_pactada' => $linea['cantidad_pactada'],
                    'cantidad_entregada' => $linea['cantidad_entregada'] ?? 0,
                    'precio_unitario' => $linea['precio_unitario'],
                ]);
            }

            return $contrato;
        });
    }

    /** Devuelve la única línea del contrato. */
    protected function primeraLinea(Contrato $contrato): LineaContrato
    {
        return $contrato->lineas()->firstOrFail();
    }

    protected function crearAbono(
        Usuario|Estudio $contexto,
        Contrato $contrato,
        int $monto,
        MetodoAbono $metodo = MetodoAbono::Transferencia,
    ): Abono {
        $estudioId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, fn (): Abono => Abono::query()->create([
            'tenant_id' => $estudioId,
            'contrato_id' => $contrato->id,
            'codigo' => 'A-' . substr(md5($contrato->id . $monto), 0, 8),
            'fecha' => today()->toDateString(),
            'monto' => $monto,
            'metodo' => $metodo->value,
        ]));
    }

    /**
     * Crea una sesión y, opcionalmente, una selección confirmada.
     *
     * El parámetro `seleccionConfirmada` existe porque la regla central de esta
     * aplicación es que no se entrega sin selección: la prueba tiene que poder
     * armar las dos situaciones sin pasar por la base a mano.
     */
    protected function crearSesion(
        Usuario|Estudio $contexto,
        Cliente $cliente,
        EstadoSesion $estado = EstadoSesion::PendienteSeleccion,
        bool $seleccionConfirmada = false,
        ?int $cantidadFotos = 3,
    ): Sesion {
        $estudioId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, function () use ($estudioId, $cliente, $estado, $seleccionConfirmada, $cantidadFotos): Sesion {
            $sesion = Sesion::query()->create([
                'tenant_id' => $estudioId,
                'cliente_id' => $cliente->id,
                'tipo' => TipoSesion::Cumpleanos->value,
                'titulo' => 'Cumpleaños de prueba',
                'fecha_prometida' => now()->subDays(20)->toDateString(),
                'fecha_realizada' => now()->subDays(20)->toDateString(),
                'estado' => $estado->value,
                'fecha_prometida_entrega' => now()->addDays(10)->toDateString(),
            ]);

            for ($i = 0; $i < ($cantidadFotos ?? 0); $i++) {
                $sesion->fotos()->create([
                    'tenant_id' => $estudioId,
                    'nombre_archivo' => 'FOTO-' . $i . '.ARW',
                    'extension' => 'ARW',
                    'bytes' => 12_000_000,
                    // Suma inventada a propósito: la idea es que sea un dato
                    // estable y no un hash calculado, porque lo que se prueba es
                    // el saldo, no la criptografía.
                    'huella_sha256' => str_repeat('a', 64),
                    'ruta_relativa' => 'bautismo/foto-' . $i . '.ARW',
                ]);
            }

            if ($seleccionConfirmada) {
                $seleccion = $sesion->seleccion()->create([
                    'tenant_id' => $estudioId,
                    'cliente_id' => $cliente->id,
                    'estado' => 'confirmada',
                    'cantidad_seleccionada' => $cantidadFotos ?? 0,
                    'fecha_confirmacion' => now(),
                ]);

                foreach ($sesion->fotos()->get() as $foto) {
                    $seleccion->detalles()->create([
                        'tenant_id' => $estudioId,
                        'foto_id' => $foto->id,
                        'elegida' => true,
                    ]);
                }
            }

            return $sesion;
        });
    }

    protected function crearConservacion(
        Usuario|Estudio $contexto,
        string $nombre = 'Disco de archivo',
    ): Conservacion {
        $estudioId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return $this->dentroDe($contexto, fn (): Conservacion => Conservacion::query()->create([
            'tenant_id' => $estudioId,
            'nombre' => $nombre,
            'tipo_medio' => 'disco_local',
            'ubicacion' => '/mnt/archivo',
            'estado' => 'activa',
            'ultima_verificacion_en' => now()->subDays(3),
        ]));
    }

    protected function idDe(object $modelo): string
    {
        return (string) $modelo->id;
    }

    protected function idDeFoto(Sesion $sesion): string
    {
        return (string) $sesion->fotos()->firstOrFail()->id;
    }

    protected function idDeTodasLasFotos(Sesion $sesion): array
    {
        return $sesion->fotos()->pluck('id')->map(static fn ($id): string => (string) $id)->all();
    }

    /** Una foto suelta, para probar que no entre una que es de otra sesión. */
    protected function idDeFotoAjena(Foto $foto): string
    {
        return (string) $foto->id;
    }
}