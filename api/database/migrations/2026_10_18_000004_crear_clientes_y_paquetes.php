<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `clientes` y `paquetes`: de quién es el contrato y qué promete el paquete.
 *
 * POR QUÉ UN PAQUETE ES UNA LISTA, NO UN PRECIO
 * ---------------------------------------------
 * Un paquete de "sesión de dos horas y cien fotos" son dos cosas, y cada una se
 * puede entregar por separado. El paquete guarda el nombre y la descripción; lo
 * que se promete de verdad son las LÍNEAS que se crean al contratar
 * (`lineas_contrato`). Ver docs/02.
 *
 * POR QUÉ EL PAQUETE NO TIENE PRECIO FIJO
 * ----------------------------------------
 * Un estudio puede cambiar el precio de un paquete sin tocar los contratos ya
 * firmados: esos contratos guardan su propio `precio_total`. El precio del
 * paquete es solo el punto de partida, y va en la línea, no acá. Ver docs/06.
 *
 * `clientes` ES LA TABLA DE DATOS PERSONALES MÁS DELICADA DEL SISTEMA
 * -------------------------------------------------------------------
 * Un nombre, un RUT, un teléfono y un correo, de una persona que además puede
 * tener fotos de sus hijos guardadas en un disco. Por eso `usuarios.puede_ver_archivos`
 * y no el rol es lo que decide si alguien abre un original. Ver docs/07.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('nombre', 160);
            $tabla->string('apellido', 160);
            // El RUT sin puntos ni guion, como texto. Se valida en el
            // FormRequest, no en la base: la base no sabe de Chile.
            $tabla->string('rut', 16)->nullable();
            $tabla->string('telefono', 32)->nullable();
            $tabla->string('correo', 160)->nullable();
            $tabla->text('notas')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();

            // El mismo RUT puede volver al estudio años después. La única
            // unicidad es por estudio, y `null` puede repetirse: PostgreSQL
            // trata los `null` como distintos en un índice único.
            $tabla->unique(['tenant_id', 'rut']);
            $tabla->index(['tenant_id', 'nombre']);
        });

        Schema::create('paquetes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('codigo', 32);
            $tabla->string('nombre', 160);
            $tabla->text('descripcion')->nullable();
            // Lo que el estudio ofrece como precio de entrada. NO es lo que se
            // cobra: al contratar se copian las líneas con su precio. Ver la
            // nota del encabezado.
            $tabla->integer('precio_referencia');
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();

            $tabla->unique(['tenant_id', 'codigo']);
            $tabla->index(['tenant_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paquetes');
        Schema::dropIfExists('clientes');
    }
};