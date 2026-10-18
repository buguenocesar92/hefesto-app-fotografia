<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sesiones`: las tomas de fotos con fecha prometida.
 *
 * POR QUÉ ESTA TABLA ES EL EJE DEL SISTEMA
 * -----------------------------------------
 * Lo que un estudio vende y no puede fallar es una fecha. El cumple, el
 * matrimonio, el bautismo. Por eso la sesión tiene DOS fechas y las dos se
 * guardan:
 *
 *   - `fecha_prometida`: la que se le dijo a la persona. Es un compromiso.
 *   - `fecha_realizada`: la que pasó. Puede ser distinta, y por eso existe la
 *     columna en vez de sobrescribir la primera.
 *
 * Y un `estado` con cuatro valores que dicen qué tan cerca está esa fecha de
 * cumplirse. `pendiente_seleccion` NO es un error: es el estado normal de una
 * sesión que se tomó y en la que la persona todavía no eligió. Ver docs/03.
 *
 * `conservacion_id` NO ES OPCIONAL EN EL PAPEL
 * -------------------------------------------
 * El estudio entrega las fotos de por vida. Cada sesión que se entrega tiene que
 * poder decir dónde están sus originales. La columna puede estar en `null` —
una
 * sesión recién agendada no tiene originales todavía— pero la pantalla de
 * entrega la pide. Ver docs/08.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            // `null` mientras la sesión no se ha vinculado a un contrato, que es
            // lo normal: una sesión de cumpleaños puede agendarse sin haber
            // pasado por un paquete.
            $tabla->uuid('contrato_id')->nullable();
            $tabla->uuid('cliente_id');
            $tabla->uuid('fotografo_id')->nullable();
            // `cumpleanos`, `matrimonio`, `bautismo`, `retrato`, `familiar`,
            // `corporativo`, `otro`.
            $tabla->string('tipo', 24);
            $tabla->string('titulo', 200);
            $tabla->text('lugar')->nullable();
            // Ver la nota del encabezado: las dos fechas se guardan.
            $tabla->date('fecha_prometida');
            $tabla->date('fecha_realizada')->nullable();
            // `agendada`, `realizada`, `pendiente_seleccion`, `entregada`.
            $tabla->string('estado', 24)->default('agendada');
            // Salen de la línea del contrato que promete la duración, no de
            // acá. Se guardan como respaldo para poder ver la agenda sin
            // resolver el contrato.
            $tabla->integer('duracion_minutos')->nullable();
            // Fecha en que el estudio le anunció la entrega. `null` cuando no
            // hay plazo configurado, y la pantalla lo declara.
            $tabla->date('fecha_prometida_entrega')->nullable();
            // Dónde están los originales de esta sesión. Ver la nota.
            $tabla->uuid('conservacion_id')->nullable();
            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();
            $tabla->foreign('contrato_id')->references('id')->on('contratos')->nullOnDelete();
            $tabla->foreign('cliente_id')->references('id')->on('clientes')->restrictOnDelete();
            $tabla->foreign('fotografo_id')->references('id')->on('usuarios')->nullOnDelete();

            // Una persona no puede tener dos sesiones el mismo día en el mismo
            // estudio. Es la restricción más obvia del rubro y la que más
            // incumplimientos genera.
            $tabla->unique(['tenant_id', 'cliente_id', 'fecha_prometida']);

            // La agenda se lee por fecha, no por identificador.
            $tabla->index(['tenant_id', 'fecha_prometida']);
            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'contrato_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones');
    }
};