<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `laboratorios`, `pedidos_laboratorio` y sus ítems: la impresión como SUBCONTRATO.
 *
 * POR QUÉ ESTO NO ES UN CARRITO DE LA TIENDA
 * -------------------------------------------
 * Un estudio no vende papel: ENCARGA la impresión a un laboratorio externo. El
 * trabajo sale de la casa y vuelve cuando el laboratorio dice. Eso significa tres
 * cosas que un carrito de compra no tiene:
 *
 *   1. El estudio queda en las manos de otro para una parte de lo que promete.
 *   2. Hay una FECHA PROMETIDA de recepción, que es un compromiso con el
 *      cliente, no con el laboratorio.
 *   3. El laboratorio devuelve el trabajo A MEDIAS, y eso tiene que quedar
 *      registrado como faltante, no como recibido.
 *
 * `fecha_prometida_recepcion` ES EL DATO MÁS IMPORTANTE DE ESTA TABLA
 * ------------------------------------------------------------------
 * Es la fecha en que el estudio le dijo al cliente que tendría su impresión. El
 * plazo que se pactó con el laboratorio va en `dias_plazo_laboratorio`, que es
 * otro número y otro compromiso.
 *
 * `cantidad_recibida` VIVE EN EL ÍTEM, NO EN EL PEDIDO
 * ----------------------------------------------------
 * Un pedido de cincuenta impresiones puede volver con cuarenta y nueve. Si el
 * total recibido fuera una columna del pedido, el faltante se perdería al
 * promediar. Ver `estado_pedido_laboratorio` en la documentación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratorios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('nombre', 160);
            $tabla->string('contacto', 160)->nullable();
            $tabla->string('telefono', 32)->nullable();
            $tabla->string('correo', 160)->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();

            $tabla->index(['tenant_id', 'activo']);
        });

        Schema::create('pedidos_laboratorio', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('laboratorio_id');
            // `null` mientras el trabajo no se ha vinculado a una sesión. Puede
            // pasar: un álbum puede ser un encargo del estudio y no de una
            // sesión concreta.
            $tabla->uuid('sesion_id')->nullable();
            $tabla->uuid('contrato_id')->nullable();
            $tabla->string('codigo', 32);
            $tabla->date('fecha_envio')->nullable();
            // El compromiso con el CLIENTE. Ver la nota del encabezado.
            $tabla->date('fecha_prometida_recepcion')->nullable();
            // El plazo que se pidió al laboratorio. Es otro compromiso.
            $tabla->integer('dias_plazo_laboratorio')->nullable();
            $tabla->date('fecha_recepcion_real')->nullable();
            // `borrador`, `enviado`, `en_laboratorio`, `recibido`,
            // `recibido_con_fallos`, `anulado`.
            $tabla->string('estado', 24)->default('borrador');
            $tabla->integer('costo_total')->default(0);
            $tabla->text('observaciones')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();
            $tabla->foreign('laboratorio_id')->references('id')->on('laboratorios')->restrictOnDelete();
            $tabla->foreign('sesion_id')->references('id')->on('sesiones')->nullOnDelete();
            $tabla->foreign('contrato_id')->references('id')->on('contratos')->nullOnDelete();

            $tabla->unique(['tenant_id', 'codigo']);

            // El índice que usa la pantalla de vencimientos: fecha prometida y
            // estado, que es exactamente por qué se consulta.
            $tabla->index(['tenant_id', 'estado', 'fecha_prometida_recepcion']);
        });

        Schema::create('pedido_laboratorio_items', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('pedido_id');
            $tabla->uuid('seleccion_id')->nullable();
            $tabla->string('tipo', 24);
            // El formato lo declara el laboratorio de cada estudio. No hay lista
            // en el software: ver config/fotografia.php y docs/05.
            $tabla->string('formato', 60);
            $tabla->integer('cantidad_pedida');
            // Ver la nota del encabezado.
            $tabla->integer('cantidad_recibida')->default(0);
            $tabla->integer('precio_unitario');
            $tabla->text('detalle')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();
            $tabla->foreign('pedido_id')->references('id')->on('pedidos_laboratorio')->cascadeOnDelete();
            $tabla->foreign('seleccion_id')->references('id')->on('selecciones')->nullOnDelete();

            $tabla->index(['tenant_id', 'pedido_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_laboratorio_items');
        Schema::dropIfExists('pedidos_laboratorio');
        Schema::dropIfExists('laboratorios');
    }
};