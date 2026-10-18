<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orden de las semillas.
 *
 * `FotografiaEjemploSeeder` va solo porque `migrate:fresh --seed` en una
 * instalación nueva necesita un estudio, una cuenta y un paquete para poder
 * abrir la pantalla de ingreso. Ver docs/04.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FotografiaEjemploSeeder::class,
        ]);
    }
}