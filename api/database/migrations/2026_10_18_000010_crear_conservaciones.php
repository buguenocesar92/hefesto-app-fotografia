<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `conservaciones`, `conservacion_verificaciones` y `accesos_archivo`.
 *
 * ESTA ES LA MIGRACIÓN QUE NO SE PUEDE HACER CON UN CAMPO DE TEXTO
 * -----------------------------------------------------------------
 * Un estudio entrega las fotos "de por vida". Si el disco muere, se pierden las
 * sesiones de tres años, y esa es la pérdida más grande del rubro. Por eso:
 *
 *   - No hay una columna `ruta_del_disco` en `sesiones`. Hay una TABLA con un
 *     estado propio (`conservaciones`), que se puede consultar, que puede
 *     desertsarse y que dispara una revisión.
 *   - Cada revisión queda escrita (`conservacion_verificaciones`), con fecha,
 *     resultado y quién la hizo. Sin historial no hay forma de demostrar que el
 *     estudio cuidó lo que el estudio entregó.
 *   - Cada apertura de un original queda escrita (`accesos_archivo`), permitida o
 *     denegada. Los originales son datos personales, y más todavía cuando la
 *     persona fotografiada es menor de edad. Ver docs/07.
 *
 * `tipo_medio` Y POR QUÉ ES UNA LISTA CERRADA
 * -------------------------------------------
 * Disco local, red de área local, almacenamiento en la nube, cinta. La lista no
 * se puede ampliar desde la pantalla: agregar un medio nuevo es una decisión
 * sobre cómo se custodia un archivo que pertenece a otras personas.
 *
 * `ultima_verificacion_en` ES UNA COPIA, Y ESTÁ POR UNA RAZÓN
 * -----------------------------------------------------------
 * Es la fecha de la última revisión que resultó correcta. Se copia desde
 * `conservacion_verificaciones` para que la pantalla de pendientes no tenga que
 * traer todo el historial; la fuente sigue siendo la tabla de verificaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conservaciones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('nombre', 160);
            // `disco_local`, `red_area_local`, `almacenamiento_nube`, `cinta`.
            $tabla->string('tipo_medio', 32);
            // La ubicación DENTRO del medio. Se guarda aparte del `tipo_medio`
            // porque cambiar de disco no cambia la naturaleza del medio.
            $tabla->string('ubicacion', 500);
            // `activa`, `desconectada`, `danada`, `destruida`.
            $tabla->string('estado', 32)->default('activa');
            // Copia de la última verificación correcta. Ver la nota.
            $tabla->timestampTz('ultima_verificacion_en')->nullable();
            $tabla->integer('bytes_declarados')->nullable();
            $tabla->integer('sesiones_asignadas')->default(0);
            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();

            $tabla->index(['tenant_id', 'estado']);
        });

        Schema::create('conservacion_verificaciones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('conservacion_id');
            $tabla->uuid('usuario_id')->nullable();
            $tabla->timestampTz('fecha');
            // `correcta`, `con_faltantes`, `no_se_pudo_verificar`.
            $tabla->string('resultado', 32);
            $tabla->integer('archivos_revisados')->nullable();
            $tabla->integer('archivos_faltantes')->default(0);
            $tabla->text('observaciones')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();
            $tabla->foreign('conservacion_id')->references('id')->on('conservaciones')->cascadeOnDelete();
            $tabla->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();

            $tabla->index(['tenant_id', 'conservacion_id']);
            $tabla->index(['tenant_id', 'fecha']);
        });

        Schema::create('accesos_archivo', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('usuario_id')->nullable();
            $tabla->uuid('sesion_id')->nullable();
            $tabla->uuid('conservacion_id')->nullable();
            // `consulta`, `descarga`, `seleccion`, `envio_al_laboratorio`,
            // `respaldo`, `restauracion`.
            $tabla->string('accion', 32);
            $tabla->boolean('permitido');
            $tabla->string('ip', 45)->nullable();
            $tabla->timestampTz('fecha');
            $tabla->text('detalle')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();
            $tabla->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();
            $tabla->foreign('sesion_id')->references('id')->on('sesiones')->cascadeOnDelete();
            $tabla->foreign('conservacion_id')->references('id')->on('conservaciones')->cascadeOnDelete();

            $tabla->index(['tenant_id', 'fecha']);
            $tabla->index(['tenant_id', 'sesion_id']);
        });

        // `sesiones.conservacion_id` se agrega acá, y no en la migración de
        // `sesiones`, para que la clave foránea apunte a una tabla que ya existe
        // cuando se declara. El orden de las migraciones es el que manda.
        Schema::table('sesiones', function (Blueprint $tabla): void {
            $tabla->foreign('conservacion_id')
                ->references('id')
                ->on('conservaciones')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $tabla): void {
            $tabla->dropForeign(['conservacion_id']);
        });

        Schema::dropIfExists('accesos_archivo');
        Schema::dropIfExists('conservacion_verificaciones');
        Schema::dropIfExists('conservaciones');
    }
};