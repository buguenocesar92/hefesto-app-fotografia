<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `vw_estudios_publicas`: lo único que se puede leer antes de identificarse.
 *
 * POR QUÉ LA TABLA `estudios` NO LLEVA RLS
 * -----------------------------------------
 * Se consulta en el ingreso, cuando todavía no hay `app.tenant_id`: sin ella no
 * hay forma de saber a qué estudio pertenece la persona que está entrando. Es la
 * excepción consciente del patrón, no un olvido. Ver docs/03.
 *
 * LA COMPENSACIÓN ES ESTA VISTA, Y SE USA DE VERDAD
 * --------------------------------------------------
 * Tres columnas. Ni una más. Antes del ingreso no hay motivo para que el
 * navegador vea el RUT de nadie, la dirección, el correo ni el teléfono de un
 * estudio. La consulta está en App\Http\Controllers\AuthController::estudios() y
 * en ProveedorOidcAuthentik::usuarioDesdeClaims().
 *
 * Que se use de verdad es lo que diferencia una protección de una promesa. En
 * otras aplicaciones de Hefesto la vista se creó y nunca se consultó, mientras la
 * documentación afirmaba lo contrario. Por eso el comando
 * `fotografia:verificar-aislamiento` comprueba que la vista tenga EXACTAMENTE
 * estas tres columnas, y la pantalla de ingreso delega en ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW public.vw_estudios_publicas AS
            SELECT
                slug,
                nombre,
                activo
            FROM public.estudios
            WHERE activo = true;

            COMMENT ON VIEW public.vw_estudios_publicas IS
                'Tres columnas y solo estudios activos. Es lo unico que se puede leer sin sesion. La consulta esta en AuthController::estudios().';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.vw_estudios_publicas;');
    }
};