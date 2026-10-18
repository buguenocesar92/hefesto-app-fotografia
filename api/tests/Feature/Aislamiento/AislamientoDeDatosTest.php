<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Enums\RolEstudio;
use App\Models\Cliente;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El aislamiento entre estudios, comprobado sobre PostgreSQL.
 *
 * POR QUÉ ESTAS PRUEBAS Y NO UNA COMPROBACIÓN DE CÓDIGO
 * ---------------------------------------------------
 * La Row Level Security es una garantía de la BASE. Un test que leyera el texto
 * de la migración comprobaría que escribimos unas letras, no que las políticas
 * filtran. Estas pruebas crean dos estudios con filas reales, se leen con el
 * contexto de uno y se verifica que el otro no aparece.
 *
 * Y hacen la comprobación inversa, que es la que más se olvida: escribir una fila
 * declarando el estudio de otro. Sin `WITH CHECK`, esa escritura pasa, la lectura
 * la oculta y nadie la ve nunca.
 *
 * EN ESTA MÁQUINA NO SE EJECUTARON
 * ------------------------------
 * En el equipo donde se escribió esta aplicación no hay PHP, Composer, Docker ni
 * PostgreSQL. Estas pruebas NO se corrieron. Ver docs/10.
 */
class AislamientoDeDatosTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_estudio_no_ve_los_clientes_de_otro(): void
    {
        $estudioA = $this->crearEstudio('estudio-a');
        $estudioB = $this->crearEstudio('estudio-b');

        $usuarioA = $this->crearUsuario($estudioA);
        $usuarioB = $this->crearUsuario($estudioB);

        $this->crearCliente($estudioA, 'Cliente de A');
        $this->crearCliente($estudioB, 'Cliente de B');

        $vistosPorA = $this->dentroDe($usuarioA, static fn () => Cliente::query()->pluck('nombre')->all());
        $vistosPorB = $this->dentroDe($usuarioB, static fn () => Cliente::query()->pluck('nombre')->all());

        $this->assertSame(['Cliente de A'], $vistosPorA);
        $this->assertSame(['Cliente de B'], $vistosPorB);
    }

    public function test_no_se_puede_buscar_por_identificador_un_cliente_de_otro_estudio(): void
    {
        $estudioA = $this->crearEstudio('estudio-a');
        $estudioB = $this->crearEstudio('estudio-b');

        $usuarioA = $this->crearUsuario($estudioA);

        $clienteDeB = $this->crearCliente($estudioB, 'Cliente de B');

        // Buscarlo directamente por su identificador también tiene que fallar. Es
        // la forma más simple de saltarse una lista, y por eso se prueba aparte.
        $encontrado = $this->dentroDe(
            $usuarioA,
            static fn () => Cliente::query()->find($clienteDeB->id)
        );

        $this->assertNull($encontrado, 'Un estudio encontró un cliente que es de otro.');
    }

    public function test_no_se_puede_escribir_una_fila_declarando_otro_estudio(): void
    {
        $estudioA = $this->crearEstudio('estudio-a');
        $estudioB = $this->crearEstudio('estudio-b');

        $usuarioA = $this->crearUsuario($estudioA);

        $fallo = false;

        try {
            $this->dentroDe($usuarioA, static fn () => Cliente::query()->create([
                'tenant_id' => $estudioB->id,
                'nombre' => 'Intruso',
                'apellido' => 'De Prueba',
                'activo' => true,
            ]));
        } catch (\Throwable) {
            $fallo = true;
        }

        $this->assertTrue(
            $fallo,
            'Se pudo escribir un cliente declarando el estudio de otro. Falta el WITH CHECK de la política.'
        );
    }

    public function test_una_lectura_sin_contexto_falla_en_vez_de_devolver_todas_las_filas(): void
    {
        $estudio = $this->crearEstudio('estudio-a');
        $this->crearCliente($estudio, 'Cliente de A');

        $fallo = false;

        try {
            // Sin `set_config`, `app_tenant_id()` tiene que lanzar excepción.
            Cliente::query()->get();
        } catch (\Throwable) {
            $fallo = true;
        }

        $this->assertTrue(
            $fallo,
            'Una lectura SIN contexto devolvió filas. El aislamiento está abierto: '
            . 'la función app_tenant_id() tiene que lanzar excepción.'
        );
    }

    public function test_la_vista_publica_expone_exactamente_tres_columnas(): void
    {
        $columnas = DB::select(
            "SELECT column_name AS nombre
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'vw_estudios_publicas'
             ORDER BY ordinal_position"
        );

        $nombres = array_map(static fn ($columna): string => (string) $columna->nombre, $columnas);

        $this->assertSame(['slug', 'nombre', 'activo'], $nombres);

        // Y tiene que seguir usándose: si la vista se crea y nadie la consulta,
        // es una promesa, no una protección.
        $this->assertStringContainsString(
            'vw_estudios_publicas',
            (string) file_get_contents(__DIR__ . '/../../../app/Http/Controllers/AuthController.php'),
            'La vista existe pero el controlador de ingreso no la está usando.'
        );
    }

    public function test_las_tablas_de_archivos_tienen_row_level_security_forzado(): void
    {
        // Las tres tablas que guardan quién abrió qué original y dónde están los
        // archivos. Una sin RLS sería la puerta más grave del sistema.
        foreach (['accesos_archivo', 'conservaciones', 'conservacion_verificaciones'] as $tabla) {
            $estado = DB::selectOne(
                "SELECT c.relrowsecurity AS rls, c.relforcerowsecurity AS forzada
                 FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = 'public' AND c.relname = ?",
                [$tabla]
            );

            $this->assertNotNull($estado, "La tabla {$tabla} no existe.");
            $this->assertTrue((bool) $estado->rls, "La tabla {$tabla} no tiene RLS activada.");
            $this->assertTrue((bool) $estado->forzada, "La tabla {$tabla} no tiene RLS FORZADA.");
        }
    }

    public function test_la_conexion_no_es_superusuario(): void
    {
        // Es la primera comprobación del comando `fotografia:verificar-aislamiento`
        // por una razón concreta: con superusuario, PostgreSQL ignora TODAS las
        // políticas, y el resto de estas pruebas pasaría sin comprobar nada.
        $rol = DB::selectOne(
            'SELECT rolsuper AS superusuario, rolbypassrls AS bypass
             FROM pg_roles WHERE rolname = current_user'
        );

        $this->assertFalse((bool) $rol->superusuario, 'El rol de pruebas es superusuario.');
        $this->assertFalse((bool) $rol->bypass, 'El rol de pruebas tiene BYPASSRLS.');
    }

    public function test_las_cuentas_de_un_estudio_no_se_ven_desde_el_otro(): void
    {
        $estudioA = $this->crearEstudio('estudio-a');
        $estudioB = $this->crearEstudio('estudio-b');

        $this->crearUsuario($estudioA, RolEstudio::Administracion);
        $usuarioB = $this->crearUsuario($estudioB, RolEstudio::Administracion);

        $correos = $this->dentroDe($usuarioB, static fn () => Usuario::query()->count());

        // Solo la cuenta del estudio B. La de A no existe para B.
        $this->assertSame(1, $correos);
    }
}