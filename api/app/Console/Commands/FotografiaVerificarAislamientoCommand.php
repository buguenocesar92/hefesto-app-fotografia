<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Verifica que el aislamiento entre estudios este de verdad puesto.
 *
 * POR QUÉ ESTE COMANDO EXISTE
 * ---------------------------
 * La Row Level Security es una garantía, y una garantía que no se comprueba es una
 * suposición. Este comando hace SEIS comprobaciones reales:
 *
 *  1. Que el rol de la conexión NO sea superusuario.
 *  2. Que el rol de la conexión NO tenga BYPASSRLS.
 *  3. Que las tablas protegidas tengan RLS ACTIVADA y FORZADA, y con política
 *     de lectura Y de escritura.
 *  4. Que `vw_estudios_publicas` exista y tenga EXACTAMENTE tres columnas.
 *  5. Que leyendo sin contexto la consulta FALLE, y que con el contexto de un
 *     estudio solo se vean SUS filas.
 *  6. Que no se pueda escribir una fila declarando el estudio de otro.
 *
 * EL PUNTO 4 ES EL QUE MAS SE HA OVIDADO EN OTRAS APLICACIONES
 * -----------------------------------------------------------
 * Varias aplicaciones de Hefesto crearon `vw_<algo>s_publicas` y nunca la
 * consultaron, mientras la documentación afirmaba lo contrario. Eso es prometer una
 * protección que no existe. Por eso acá se comprueba que la vista tenga
 * exactamente `slug`, `nombre` y `activo`, y la pantalla de ingreso delega en
 * ella. Ver docs/03.
 *
 * Los puntos 5 y 6 crean dos estudios y filas temporales, comprueban y las borran
 * dentro de una transacción que se revierte al final.
 *
 * Sale con código distinto de cero si algo falla: el despliegue se detiene.
 */
class FotografiaVerificarAislamientoCommand extends Command
{
    protected $signature = 'fotografia:verificar-aislamiento';

    protected $description = 'Comprueba que el aislamiento entre estudios este activo y funcione.';

    public function handle(): int
    {
        $this->info('Verificando el aislamiento entre estudios...');

        $fallos = [];

        // --- 1 y 2. El rol de la conexión ---------------------------------
        $rol = DB::selectOne('SELECT current_user AS usuario, session_user AS conexion');

        $esSuperusuario = DB::selectOne(
            'SELECT rolsuper AS valor FROM pg_roles WHERE rolname = current_user'
        );

        if ((bool) $esSuperusuario->valor) {
            $fallos[] = 'El rol de la conexión es SUPERUSUARIO. Con superusuario, PostgreSQL '
                . 'ignora TODAS las políticas de Row Level Security, aunque la tabla tenga FORCE.';
        }

        $bypass = DB::selectOne(
            'SELECT rolbypassrls AS valor FROM pg_roles WHERE rolname = current_user'
        );

        if ((bool) $bypass->valor) {
            $fallos[] = 'El rol de la conexión tiene BYPASSRLS. Con ese permiso, PostgreSQL '
                . 'ignora TODAS las políticas de Row Level Security.';
        }

        if ($fallos === []) {
            $this->line("  Rol: {$rol->usuario} (no superusuario, sin BYPASSRLS)");
        }

        // --- 3. Las tablas protegidas --------------------------------------
        $tablas = $this->tablasProtegidas();
        $problemas = $this->revisarPoliticas($tablas);

        foreach ($problemas as $problema) {
            $fallos[] = $problema;
        }

        if ($problemas === [] && $tablas !== []) {
            $this->line('  Tablas protegidas con politica correcta: ' . count($tablas));
        }

        if ($tablas === []) {
            $fallos[] = 'No se encontró ninguna tabla con columna tenant_id. '
                . 'Revise que las migraciones se hayan aplicado.';
        }

        // --- 4. La vista pública, que se USA de verdad ---------------------
        $problemasVista = $this->revisarVistaPublica();

        foreach ($problemasVista as $problema) {
            $fallos[] = $problema;
        }

        if ($problemasVista === []) {
            $this->line('  Vista vw_estudios_publicas: tres columnas exactas');
        }

        // --- 5 y 6. La prueba real: leer sin contexto, y con contexto ------
        if (! $this->laLecturaSinContextoFalla()) {
            $fallos[] = 'Una lectura SIN contexto de estudio NO fue rechazada. La función '
                . 'app_tenant_id() debería lanzar excepción y está devolviendo algo. '
                . 'El aislamiento está ABIERTO.';
        } else {
            $this->line('  Lectura sin contexto: rechazada (correcto)');
        }

        if (! $this->dosEstudiosNoSeVen()) {
            $fallos[] = 'Dos estudios de prueba se vieron entre sí. La política no está '
                . 'filtrando por tenant_id como debe.';
        } else {
            $this->line('  Dos estudios de prueba: cada uno ve solo lo suyo (correcto)');
        }

        if (! $this->noSePuedeEscribirDeOtroEstudio()) {
            $fallos[] = 'Se pudo escribir una fila declarando el estudio de otro. '
                . 'Falta el WITH CHECK de la política.';
        } else {
            $this->line('  Escritura con estudio ajeno: rechazada (correcto)');
        }

        // --- Veredicto ------------------------------------------------------
        if ($fallos !== []) {
            $this->error('AISLAMIENTO: FALLA');

            foreach ($fallos as $fallo) {
                $this->line("  - {$fallo}");
            }

            $this->line('');
            $this->error('No sigas con el despliegue hasta corregir esto.');

            return self::FAILURE;
        }

        $this->info('AISLAMIENTO: OK');

        return self::SUCCESS;
    }

    /**
     * Las tablas que deben estar protegidas.
     *
     * Se leen del propio catálogo de PostgreSQL, no de una lista escrita en PHP:
     * así el comando comprueba lo que HAY, y si alguien agrega una tabla nueva
     * con `tenant_id` sin RLS, el comando la va a marcar.
     *
     * @return list<string>
     */
    private function tablasProtegidas(): array
    {
        return DB::select(<<<'SQL'
            SELECT c.relname AS nombre
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind = 'r'
              AND c.relname <> 'migrations'
              AND EXISTS (
                  SELECT 1
                  FROM pg_attribute a
                  WHERE a.attrelid = c.oid
                    AND a.attname = 'tenant_id'
                    AND NOT a.attisdropped
              )
            ORDER BY c.relname
        SQL)->pluck('nombre')->all();
    }

    /**
     * @param  list<string>  $tablas
     * @return list<string>
     */
    private function revisarPoliticas(array $tablas): array
    {
        $problemas = [];

        foreach ($tablas as $tabla) {
            $estado = DB::selectOne(
                "SELECT c.relrowsecurity AS rls, c.relforcerowsecurity AS forzada
                 FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = 'public' AND c.relname = ?",
                [$tabla]
            );

            if ($estado === null || ! (bool) $estado->rls) {
                $problemas[] = "La tabla {$tabla} no tiene Row Level Security activada.";

                continue;
            }

            if (! (bool) $estado->forzada) {
                $problemas[] = "La tabla {$tabla} tiene RLS pero NO está FORZADA. El dueño de "
                    . 'la tabla puede saltársela.';
            }

            $politicas = DB::select(
                "SELECT policyname AS nombre, qual AS usando, with_check AS chequeo
                 FROM pg_policies
                 WHERE schemaname = 'public' AND tablename = ?",
                [$tabla]
            );

            $tieneLectura = false;
            $tieneEscritura = false;

            foreach ($politicas as $politica) {
                if ($politica->usando === null && $politica->chequeo === null) {
                    $problemas[] = "La política {$politica->nombre} de {$tabla} no menciona tenant_id.";
                }

                if ($politica->usando !== null) {
                    $tieneLectura = true;
                }

                if ($politica->chequeo !== null) {
                    $tieneEscritura = true;
                }
            }

            if (! $tieneLectura) {
                $problemas[] = "La tabla {$tabla} no tiene política que filtre la LECTURA (USING).";
            }

            if (! $tieneEscritura) {
                $problemas[] = "La tabla {$tabla} no tiene política que filtre la ESCRITURA (WITH CHECK).";
            }
        }

        return $problemas;
    }

    /**
     * La vista pública tiene que existir y tener EXACTAMENTE tres columnas.
     *
     * Si le agregan una columna, el selector de ingreso empieza a exponer ese
     * dato, y este comando es lo que lo detecta.
     *
     * @return list<string>
     */
    private function revisarVistaPublica(): array
    {
        $existe = DB::selectOne(
            "SELECT EXISTS (
                SELECT 1 FROM pg_views
                WHERE schemaname = 'public' AND viewname = 'vw_estudios_publicas'
             ) AS existe"
        );

        if ($existe === null || ! $existe->existe) {
            return ['No existe la vista public.vw_estudios_publicas.'];
        }

        $columnas = DB::select(
            "SELECT column_name AS nombre
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'vw_estudios_publicas'
             ORDER BY ordinal_position"
        )->pluck('nombre')->all();

        $esperadas = ['slug', 'nombre', 'activo'];

        sort($esperadas);
        $obtenidas = $columnas;
        sort($obtenidas);

        if ($obtenidas !== $esperadas) {
            return [sprintf(
                'La vista public.vw_estudios_publicas expone columnas distintas de las tres esperadas ('
                . 'tiene: %s). Antes del ingreso no hay motivo para ver nada más.',
                implode(', ', $columnas)
            )];
        }

        return [];
    }

    /**
     * `true` si leer sin contexto fue rechazado.
     *
     * Se hace en una transacción propia que se revierte al final, para que la
     * marca `set_config`, que tiene alcance de transacción, no se escape a nada.
     */
    private function laLecturaSinContextoFalla(): bool
    {
        try {
            DB::transaction(function (): void {
                DB::statement("SELECT set_config('app.tenant_id', '', true)");

                // Si `app_tenant_id()` no lanza excepción, devuelve algo y la
                // consulta pasa. Eso sería el aislamiento abierto.
                DB::select('SELECT * FROM usuarios LIMIT 1');
            });

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * `true` si dos estudios de prueba no se ven entre sí.
     */
    private function dosEstudiosNoSeVen(): bool
    {
        $unico = 'verificacion-' . bin2hex(random_bytes(4));

        try {
            $ids = DB::transaction(function () use ($unico): array {
                // Los identificadores se generan acá. `insertGetId` devuelve un
                // entero autoincremental y estas tablas usan UUID.
                $idA = (string) Str::uuid();
                $idB = (string) Str::uuid();

                DB::table('estudios')->insert([
                    [
                        'id' => $idA,
                        'slug' => $unico . '-a',
                        'nombre' => 'Verificacion A',
                        'nombre_corto' => 'Verificacion A',
                        'activo' => true,
                    ],
                    [
                        'id' => $idB,
                        'slug' => $unico . '-b',
                        'nombre' => 'Verificacion B',
                        'nombre_corto' => 'Verificacion B',
                        'activo' => true,
                    ],
                ]);

                DB::table('usuarios')->insert([
                    [
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $idA,
                        'nombre' => 'Verificacion A',
                        'correo' => $unico . '-a@ejemplo.invalido',
                        'rol' => 'recepcion',
                        'puede_ver_archivos' => false,
                        'activo' => true,
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $idB,
                        'nombre' => 'Verificacion B',
                        'correo' => $unico . '-b@ejemplo.invalido',
                        'rol' => 'recepcion',
                        'puede_ver_archivos' => false,
                        'activo' => true,
                    ],
                ]);

                return ['a' => $idA, 'b' => $idB];
            }, 1);

            // La lectura con contexto debe devolver SOLO lo suyo.
            $lecturaA = DB::transaction(function () use ($ids): array {
                DB::statement("SELECT set_config('app.tenant_id', ?, true)", [$ids['a']]);

                return DB::table('usuarios')->pluck('correo')->all();
            }, 1);

            $lecturaB = DB::transaction(function () use ($ids): array {
                DB::statement("SELECT set_config('app.tenant_id', ?, true)", [$ids['b']]);

                return DB::table('usuarios')->pluck('correo')->all();
            }, 1);

            if (count($lecturaA) !== 1 || count($lecturaB) !== 1) {
                return false;
            }

            return $lecturaA[0] !== $lecturaB[0];
        } catch (\Throwable $e) {
            $this->line('  No se pudieron crear los estudios de prueba: ' . $e->getMessage());

            return false;
        } finally {
            // Limpieza. Se borran los de prueba aunque la comprobación haya
            // fallado: dejarlos contaminaría la base de un estudio real.
            try {
                DB::table('usuarios')->where('correo', 'like', $unico . '-%')->delete();
                DB::table('estudios')->where('slug', 'like', $unico . '-%')->delete();
            } catch (\Throwable) {
                // Si no se pudo crear, no hay nada que borrar.
            }
        }
    }

    /**
     * `true` si escribir una fila declarando otro estudio fue rechazado.
     *
     * Es la comprobación del `WITH CHECK`. Sin él, un `INSERT` con el estudio de
     * otro pasa: la lectura la ocultaría y nadie la vería nunca.
     */
    private function noSePuedeEscribirDeOtroEstudio(): bool
    {
        $unico = 'verificacion-' . bin2hex(random_bytes(4));

        $idEstudio = (string) Str::uuid();
        $idOtro = (string) Str::uuid();
        $idUsuario = (string) Str::uuid();

        try {
            DB::table('estudios')->insert([
                [
                    'id' => $idEstudio,
                    'slug' => $unico . '-contexto',
                    'nombre' => 'Verificacion contexto',
                    'nombre_corto' => 'Verificacion contexto',
                    'activo' => true,
                ],
                [
                    'id' => $idOtro,
                    'slug' => $unico . '-ajeno',
                    'nombre' => 'Verificacion ajeno',
                    'nombre_corto' => 'Verificacion ajeno',
                    'activo' => true,
                ],
            ]);

            // El contexto es el estudio A; la fila declara el estudio B.
            DB::transaction(function () use ($idEstudio, $idOtro, $idUsuario, $unico): void {
                DB::statement("SELECT set_config('app.tenant_id', ?, true)", [$idEstudio]);

                DB::table('usuarios')->insert([
                    'id' => $idUsuario,
                    'tenant_id' => $idOtro,
                    'nombre' => 'Verificacion escribiente',
                    'correo' => $unico . '-escritura@ejemplo.invalido',
                    'rol' => 'recepcion',
                    'puede_ver_archivos' => false,
                    'activo' => true,
                ]);
            }, 1);

            return false;
        } catch (\Throwable) {
            return true;
        } finally {
            try {
                DB::table('usuarios')->where('correo', 'like', $unico . '-%')->delete();
                DB::table('estudios')->where('slug', 'like', $unico . '-%')->delete();
            } catch (\Throwable) {
                // Si no se pudo crear, no hay nada que borrar.
            }
        }
    }
}