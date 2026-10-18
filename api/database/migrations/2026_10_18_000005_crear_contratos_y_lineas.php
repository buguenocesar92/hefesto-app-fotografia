<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `contratos` y `lineas_contrato`: el paquete ya contratado.
 *
 * ESTA ES LA TABLA DE LA QUE HABLA EL REQUERIMIENTO
 * -------------------------------------------------
 * Lo que un estudio vende es un paquete que todavía NO existe cuando se contrata.
 * El contrato es la foto de ese paquete en el momento de la firma, y la línea es
 * lo que permite decir, en cualquier momento, cuánto se entregó y cuánto falta.
 *
 * `cantidad_entregada` VIVE EN LA LÍNEA, NO EN EL CONTRATO
 * ------------------------------------------------------
 * Si el total entregado fuera una columna del contrato, habría que recalcularlo
 * cada vez y no se podría decir DE QUÉ se entregó. Con la cantidad en la línea,
 * el desglose sale de una lectura y es auditable línea por línea. Ver
 * App\Services\Cobranza\CalculadoraDeSaldo.
 *
 * POR QUÉ UNA ENTREGA PARCIAL NO CIERRA EL CONTRATO
 * -------------------------------------------------
 * Entregar veinte de cien fotos no es cerrar el contrato: es avanzar en él. Por
 * eso `cantidad_entregada` puede estar entre cero y `cantidad_pactada`, y el
 * contrato sigue en `activo`. El estado `cerrado` solo se alcanza cuando todas
 * las líneas están completas. Ver App\Services\Contratos\RegistradorDeEntrega.
 *
 * `precio_unitario` EN PESOS, COMO ENTERO
 * ---------------------------------------
 * Nunca como decimal ni como texto. Un saldo mal redondeado se convierte en una
 * diferencia de caja que nadie sabe de dónde salió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('cliente_id');
            // `null` cuando el contrato se armó a medida, sin paquete de catálogo.
            $tabla->uuid('paquete_id')->nullable();
            $tabla->string('codigo', 32);
            $tabla->date('fecha_contrato');
            // El precio pactado en la firma. Se congela acá a propósito: cambiar
            // el precio del paquete después no cambia lo que este cliente firmó.
            $tabla->integer('precio_total');
            // `borrador`, `activo`, `cerrado`, `anulado`.
            $tabla->string('estado', 24)->default('borrador');
            // Días que se prometedieron entre la sesión y la entrega. `null`
            // significa "no hay plazo configurado", y la pantalla lo dice. Ver
            // config/fotografia.php y docs/06.
            $tabla->integer('dias_promesa_entrega')->nullable();
            $tabla->timestampTz('fecha_cierre')->nullable();
            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();
            $tabla->foreign('cliente_id')->references('id')->on('clientes')->restrictOnDelete();
            $tabla->foreign('paquete_id')->references('id')->on('paquetes')->nullOnDelete();

            $tabla->unique(['tenant_id', 'codigo']);
            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'cliente_id']);
        });

        Schema::create('lineas_contrato', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('contrato_id');
            // `sesion`, `foto_entregada`, `impresion`, `album`,
            // `archivo_digital`, `adicional`.
            $tabla->string('tipo', 24);
            $tabla->string('concepto', 200);
            // Para una línea de sesión: minutos. Para las demás: unidades.
            $tabla->integer('unidad_minutos')->nullable();
            $tabla->integer('cantidad_pactada')->default(1);
            // Ver la nota del encabezado: la cantidad entregada vive en la línea.
            $tabla->integer('cantidad_entregada')->default(0);
            $tabla->integer('precio_unitario');
            $tabla->text('detalle')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();
            $tabla->foreign('contrato_id')->references('id')->on('contratos')->cascadeOnDelete();

            $tabla->index(['tenant_id', 'contrato_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lineas_contrato');
        Schema::dropIfExists('contratos');
    }
};