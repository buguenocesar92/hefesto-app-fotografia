<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `fotos`, `selecciones` y `seleccion_detalles`: qué se eligió y qué se imprime.
 *
 * POR QUÉ LA SELECCIÓN ES UNA TABLA Y NO UNA COLUMA
 * ------------------------------------------------
 * "Pendiente de selección" es el estado NORMAL de una sesión que se tomó y en la
 * que la persona todavía no eligió. El sistema no lo trata como un error, y por
 * eso la selección es un objeto con estado, fecha y detalles, no una marca.
 *
 * Y de acá sale la regla que más plata protege: una sesión NO pasa a `entregada`
 * sin una selección `confirmada`. Hasta ese momento no hay nada que imprimir, y
 * lo que se paga por imprimir algo que nadie eligió no vuelve. Ver
 * App\Services\Sesiones\GestorDeEstadoDeSesion.
 *
 * `huella_sha256` Y POR QUÉ ESTÁ EN LA TABLA
 * -------------------------------------------
 * Es la misma suma que va en el manifiesto de los respaldos. Con ella, dos años
 * después se puede probar que el archivo que se entregó es el mismo que se
 * escaneó: es lo que permite auditar una custodia. Ver docs/07 y docs/08.
 *
 * `seleccion_detalles` ES LA PUENTE ENTRE LAS DOS TABLAS
 * -----------------------------------------------------
 * Lleva `tenant_id` y la misma Row Level Security que las demás. Una tabla de
 * unión sin `tenant_id` sería la puerta por la que se leería la selección de otro
 * estudio, y el comando de verificación lo detecta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fotos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('sesion_id');
            $tabla->string('nombre_archivo', 240);
            $tabla->string('extension', 16)->nullable();
            $tabla->bigInteger('bytes')->nullable();
            // Ver la nota del encabezado.
            $tabla->char('huella_sha256', 64)->nullable();
            // La ruta DENTRO del medio declarado en `sesiones.conservacion_id`.
            // No es un sistema de archivos: es un nombre relativo, y sin la
            // conservación no significa nada. Ver docs/08.
            $tabla->string('ruta_relativa', 500)->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();
            $tabla->foreign('sesion_id')->references('id')->on('sesiones')->cascadeOnDelete();

            $tabla->index(['tenant_id', 'sesion_id']);
        });

        Schema::create('selecciones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('sesion_id');
            $tabla->uuid('cliente_id');
            // `pendiente`, `en_curso`, `confirmada`.
            $tabla->string('estado', 24)->default('pendiente');
            $tabla->integer('cantidad_seleccionada')->default(0);
            $tabla->timestampTz('fecha_confirmacion')->nullable();
            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();
            $tabla->foreign('sesion_id')->references('id')->on('sesiones')->cascadeOnDelete();
            $tabla->foreign('cliente_id')->references('id')->on('clientes')->restrictOnDelete();

            // Una sesión tiene una selección viva a la vez. Volver a elegir no
            // crea una nueva fila: actualiza la que hay, para que el historial
            // de "cuándo se eligió" no se pierda al reintentar.
            $tabla->unique(['tenant_id', 'sesion_id']);
            $tabla->index(['tenant_id', 'estado']);
        });

        Schema::create('seleccion_detalles', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('seleccion_id');
            $tabla->uuid('foto_id');
            $tabla->boolean('elegida')->default(false);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();
            $tabla->foreign('seleccion_id')->references('id')->on('selecciones')->cascadeOnDelete();
            $tabla->foreign('foto_id')->references('id')->on('fotos')->cascadeOnDelete();

            $tabla->unique(['tenant_id', 'seleccion_id', 'foto_id']);
            $tabla->index(['tenant_id', 'seleccion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seleccion_detalles');
        Schema::dropIfExists('selecciones');
        Schema::dropIfExists('fotos');
    }
};