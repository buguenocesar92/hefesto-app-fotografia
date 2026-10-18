<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AISLAMIENTO ENTRE ESTUDIOS: Row Level Security de PostgreSQL.
 *
 * PATRÓN REUTILIZADO, NO INVENTADO
 * --------------------------------
 * Es el mismo patrón de `apps/panaderia/docs/03-MULTITENANCY-Y-ROLES.md`,
 * repetido en las demás aplicaciones de Hefesto: columna `tenant_id`, políticas
 * de RLS con `USING` (lectura) y `WITH CHECK` (escritura), y
 * `FORCE ROW LEVEL SECURITY`. No hay un cuarto enfoque.
 *
 * CUATRO COSAS QUE HACE ESTA MIGRACIÓN
 * ------------------------------------
 * 1. `public.app_tenant_id()` Lanza excepción si no hay contexto. Podría
 *    devolver `NULL` y filtrar todo en silencio; se eligió que falle ruidoso,
 *    para que el registro diga exactamente qué operación se saltó el middleware.
 * 2. Política `FOR ALL` con `USING` y `WITH CHECK`. Sin `WITH CHECK` se podría
 *    insertar una fila declarando otro estudio: la lectura la ocultaría y nadie la
 *    vería nunca.
 * 3. `FORCE ROW LEVEL SECURITY` en cada tabla, para que la política aplique
 *    también al dueño de la tabla. En desarrollo el rol de la aplicación suele
 *    ser el mismo que creó las tablas; sin `FORCE` el aislamiento sería
 *    decorativo justo donde se prueba.
 * 4. `set_config(..., true)`, con alcance de TRANSACCIÓN. Ver
 *    `App\Services\Tenancy\ContextoEstudio`: el worker de colas reutiliza la
 *    conexión y con un `SET` de sesión arrastraría el contexto de otro estudio.
 *
 * LAS TRES TABLAS QUE NO ESTÁN EN LA LISTA
 * -----------------------------------------
 *  - `estudios`: se consulta en el ingreso, antes de que exista sesión. Se
 *    compensa con la vista `vw_estudios_publicas`.
 *  - `sesion_web`: la cookie de sesión de Laravel. No contiene datos de negocio.
 *  - `migrations`, `cache`, `jobs` y las demás de Laravel: no son del dominio.
 *
 * LA COMPROBACIÓN DE `tenant_id` NO ES DECORATIVA
 * ----------------------------------------------
 * Antes de proteger nada se verifica, tabla por tabla, que la columna exista. Sin
 * esa comprobación, un error de tipeo en la lista dejaría `accesos_archivo` —que
 * guarda quién abrió los originales de qué sesión— sin protección y la
 * migración pasaría igual.
 *
 * REQUISITO DE DESPLIEGUE: el rol de la aplicación no puede ser superusuario ni
 * tener BYPASSRLS. Con superusuario, PostgreSQL ignora las políticas por
 * completo. Ver `deploy/postgres/inicializar-rol.sh` y docs/03.
 */
return new class extends Migration
{
    /**
     * Tablas de negocio, todas con `tenant_id`.
     *
     * @var list<string>
     */
    private const TABLAS = [
        'usuarios',
        'clientes',
        'paquetes',
        'contratos',
        'lineas_contrato',
        'sesiones',
        'fotos',
        'selecciones',
        'seleccion_detalles',
        'laboratorios',
        'pedidos_laboratorio',
        'pedido_laboratorio_items',
        'abonos',
        'conservaciones',
        'conservacion_verificaciones',
        'accesos_archivo',
    ];

    public function up(): void
    {
        $this->crearFuncionDeContexto();

        foreach (self::TABLAS as $tabla) {
            $this->exigirColumnaTenant($tabla);
            $this->proteger($tabla);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLAS) as $tabla) {
            DB::statement('ALTER TABLE public.' . $tabla . ' NO FORCE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE public.' . $tabla . ' DISABLE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS politica_aislamiento_estudio ON public.' . $tabla);
        }

        DB::statement('DROP FUNCTION IF EXISTS public.app_tenant_id()');
    }

    /**
     * Función que lee el contexto de estudio de la petición.
     *
     * `current_setting(..., true)` devuelve `NULL` si la variable no existe, en
     * vez de fallar, que es justo el caso que hay que detectar.
     */
    private function crearFuncionDeContexto(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                valor text;
            BEGIN
                -- `missing_ok = true`: si la marca no esta, se devuelve NULL
                -- en vez de lanzar excepcion de PostgreSQL, para que el error
                -- sea el de esta funcion y no uno de PostgreSQL que nadie
                -- entendio.
                valor := current_setting('app.tenant_id', true);

                -- FALLA CERRADO. Sin contexto no hay lectura. La alternativa
                -- (devolver NULL y que `tenant_id = NULL` no casara con
                -- nada) tambien cierra, pero devuelve un error de PostgreSQL
                -- que no dice nada: el error tiene que DIR el por que.
                IF valor IS NULL OR valor = '' THEN
                    RAISE EXCEPTION
                        'No hay estudio en el contexto. Se intento leer una tabla protegida sin estudio: el aislamiento falla cerrado.'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN valor::uuid;
            END;
            $$;

            COMMENT ON FUNCTION public.app_tenant_id() IS
                'Devuelve el estudio de la peticion. Lanza excepcion si no hay contexto: el aislamiento falla cerrado.';
        SQL);
    }

    /**
     * Falla ruidosamente si alguna tabla de la lista no tiene `tenant_id`.
     *
     * Sin esta comprobación, un error de tipeo en la lista dejaría una tabla de
     * datos personales sin protección y la migración pasaría igual.
     */
    private function exigirColumnaTenant(string $tabla): void
    {
        $fila = DB::selectOne(
            'SELECT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = \'public\' AND table_name = ? AND column_name = \'tenant_id\'
             ) AS tiene_columna',
            [$tabla],
        );

        if ($fila === null || ! $fila->tiene_columna) {
            throw new RuntimeException(
                'La tabla public.' . $tabla . ' no tiene columna tenant_id y no puede protegerse con RLS. '
                . 'Revise la migración antes de continuar.'
            );
        }
    }

    private function proteger(string $tabla): void
    {
        DB::statement('ALTER TABLE public.' . $tabla . ' ENABLE ROW LEVEL SECURITY');

        // Sin esto, las políticas no aplican al dueño de la tabla.
        DB::statement('ALTER TABLE public.' . $tabla . ' FORCE ROW LEVEL SECURITY');

        DB::statement(<<<SQL
            DROP POLICY IF EXISTS politica_aislamiento_estudio ON public.{$tabla};

            CREATE POLICY politica_aislamiento_estudio ON public.{$tabla}
                FOR ALL
                TO PUBLIC
                USING (tenant_id = public.app_tenant_id())
                WITH CHECK (tenant_id = public.app_tenant_id())
        SQL);
    }
};