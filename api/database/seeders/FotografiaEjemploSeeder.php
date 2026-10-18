<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EstadoConservacion;
use App\Enums\RolEstudio;
use App\Models\Conservacion;
use App\Models\Estudio;
use App\Models\Paquete;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Un estudio de ejemplo para una instalación nueva.
 *
 * LO QUE NO HACE
 * --------------
 * No crea datos de negocio inventados: ni sesiones, ni clientes, ni contratos, ni
 * fotos de nadie. Una PyME que entra y ve una familia de mentira con fotos pegadas, empieza a probar sobre datos falsos y después no sabe cuáles son
 * ciertos.
 *
 * Solo lo mínimo para poder abrir el ingreso: un estudio, su cuenta de
 * administración y un paquete vacío de ejemplo.
 *
 * EL PRECIO DEL PAQUETE
 * ---------------------
 * Va en cero a propósito. Los precios los declara el estudio, y un precio de
 * ejemplo en la base es un precio que alguien va a cobrar sin querer. Ver
 * docs/05.
 */
class FotografiaEjemploSeeder extends Seeder
{
    public function run(): void
    {
        $slug = 'estudio-ejemplo';

        if (Estudio::query()->where('slug', $slug)->exists()) {
            $this->command?->warn('El estudio de ejemplo ya existe. No se hace nada.');

            return;
        }

        $estudioId = (string) \Illuminate\Support\Str::uuid();
        $cuentaId = (string) \Illuminate\Support\Str::uuid();
        $paqueteId = (string) \Illuminate\Support\Str::uuid();
        $conservacionId = (string) \Illuminate\Support\Str::uuid();

        DB::transaction(function () use (
            $estudioId,
            $cuentaId,
            $paqueteId,
            $conservacionId,
            $slug
        ): void {
            DB::table('estudios')->insert([
                'id' => $estudioId,
                'slug' => $slug,
                'nombre' => 'Estudio de ejemplo',
                'nombre_corto' => 'Ejemplo',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::statement("SELECT set_config('app.tenant_id', ?, true)", [$estudioId]);

            DB::table('usuarios')->insert([
                'id' => $cuentaId,
                'tenant_id' => $estudioId,
                'nombre' => 'Administración del estudio',
                'correo' => 'administracion@ejemplo.invalido',
                'rol' => RolEstudio::Administracion->value,
                'puede_ver_archivos' => true,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('paquetes')->insert([
                'id' => $paqueteId,
                'tenant_id' => $estudioId,
                'codigo' => 'SIN-DEFINIR',
                'nombre' => 'Paquete por definir',
                'descripcion' => 'Este paquete no tiene precio ni líneas. '
                    . 'Defina los paquetes que el estudio ofrece antes de abrir el primer contrato.',
                'precio_referencia' => 0,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // El medio con originales existe desde el primer día, aunque esté
            // vacío. Un estudio que empieza sin saber dónde va a guardar las
            // fotos es un estudio que pierde las primeras.
            DB::table('conservaciones')->insert([
                'id' => $conservacionId,
                'tenant_id' => $estudioId,
                'nombre' => 'Medio por definir',
                'tipo_medio' => 'disco_local',
                'ubicacion' => 'Sin configurar',
                'estado' => EstadoConservacion::Activa->value,
                'ultima_verificacion_en' => null,
                'bytes_declarados' => null,
                'sesiones_asignadas' => 0,
                'notas' => 'Configure la ubicación real antes de recibir la primera sesión. Ver docs/08.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 1);

        $this->command?->info('Estudio de ejemplo creado.');
        $this->command?->line('  Identificador: ' . $slug);
        $this->command?->line('  Cuenta: administracion@ejemplo.invalido');
        $this->command?->line('');
        $this->command?->comment(
            'Ese correo NO es una cuenta real: la contraseña vive en Authentik. '
            . 'Cree en Authentik el equipo del estudio antes de intentar entrar. Ver docs/04.'
        );
        $this->command?->comment(
            'El paquete y el medio salen sin precio y sin ubicación: los declara el estudio. Ver docs/05.'
        );
    }
}