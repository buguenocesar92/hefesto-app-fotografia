<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RolEstudio;
use App\Models\Estudio;
use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Da de alta un estudio con su primera cuenta de administración.
 *
 * POR QUÉ ES LA EXCEPCIÓN CONTROLADA AL AISLAMIENTO
 * -------------------------------------------------
 * Las tablas de negocio tienen `tenant_id` y una política que compara esa columna
 * con `app.tenant_id()`. Crear un estudio y una cuenta es escribir en tablas
 * protegidas SIN contexto de estudio, y la base lo va a rechazar: así tiene que
 * ser.
 *
 * Este comando hace esa escritura por la vía corta y controlada: inserta el
 * estudio con la conexión normal, y para el usuario usa `withoutGlobalScopes` y
 * una transacción con el contexto ya puesto. No es un hueco en el patrón; es el
 * mismo patrón aplicado al revés, una sola vez, por una persona.
 *
 * CUANDO CORRE
 * ------------
 * Una vez por estudio, en la instalación. Después, la cuenta se administra desde
 * la pantalla. Ver docs/04.
 */
class FotografiaCrearEstudioCommand extends Command
{
    protected $signature = 'fotografia:crear-estudio
        {--slug= : Identificador corto, el que viaja en el equipo de Authentik}
        {--nombre= : Nombre del estudio}
        {--nombre-corto= : Nombre abreviado, para la agenda}
        {--correo= : Correo de la primera cuenta de administración}
        {--persona= : Nombre de esa persona}';

    protected $description = 'Da de alta un estudio con su primera cuenta de administración.';

    public function handle(): int
    {
        $slug = trim((string) ($this->option('slug') ?? ''));
        $nombre = trim((string) ($this->option('nombre') ?? ''));
        $nombreCorto = trim((string) ($this->option('nombre-corto') ?? ''));
        $correo = strtolower(trim((string) ($this->option('correo') ?? '')));
        $persona = trim((string) ($this->option('persona') ?? ''));

        if ($slug === '' || $nombre === '' || $correo === '') {
            $this->error('Faltan datos. Se necesitan --slug, --nombre y --correo.');

            return self::FAILURE;
        }

        if ($nombreCorto === '') {
            $nombreCorto = mb_substr($nombre, 0, 64);
        }

        if ($persona === '') {
            $persona = $nombreCorto;
        }

        if (Estudio::query()->where('slug', $slug)->exists()) {
            $this->error("Ya existe un estudio con el identificador «{$slug}».");

            return self::FAILURE;
        }

        $idEstudio = (string) Str::uuid();
        $idUsuario = (string) Str::uuid();

        DB::transaction(function () use ($idEstudio, $idUsuario, $slug, $nombre, $nombreCorto, $correo, $persona): void {
            DB::table('estudios')->insert([
                'id' => $idEstudio,
                'slug' => $slug,
                'nombre' => $nombre,
                'nombre_corto' => $nombreCorto,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Con el contexto ya fijado, la Row Level Security deja pasar la
            // escritura porque `tenant_id` coincide. Ver el encabezado.
            DB::statement("SELECT set_config('app.tenant_id', ?, true)", [$idEstudio]);

            DB::table('usuarios')->insert([
                'id' => $idUsuario,
                'tenant_id' => $idEstudio,
                'nombre' => $persona,
                'correo' => $correo,
                'rol' => RolEstudio::Administracion->value,
                // La primera cuenta de administración SÍ abre originales: sin
                // eso no hay forma de configurar ni de verificar conservaciones.
                'puede_ver_archivos' => true,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 1);

        $this->info("Estudio «{$nombre}» creado con el identificador «{$slug}».");
        $this->line("  Cuenta de administracion: {$correo} ({$persona})");

        $this->line('');
        $this->line('SIGUIENTE PASO, Y NO ES OPCIONAL:');
        $this->line('  1. Cree en Authentik el equipo que declara a este estudio, con el');
        $this->line('     nombre del grupo «' . config('authentik.prefijo_estudio') . '-{$slug}».');
        $this->line('  2. Agregue esa persona a un equipo de rol.');
        $this->line('  3. Corra: php artisan fotografia:verificar-aislamiento');
        $this->line('');
        $this->comment('Ver docs/04 y docs/05.');

        return self::SUCCESS;
    }
}