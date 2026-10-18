<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `abonos`: la caja.
 *
 * POR QUÉ ESTA TABLA ESTÁ SEPARADA DE LA ENTREGA
 * ----------------------------------------------
 * Son dos hechos distintos y no se pueden mezclar. Que se hayan entregado veinte
 * fotos no dice que se hayan pagado. Que se haya pagado no dice que se haya
 * entregado. Un estudio que junta las dos cosas en una sola columna no puede
 * responder ni "¿qué falta entregar?" ni "¿quién me debe?", que son las dos
 * preguntas con las que se vive.
 *
 * La respuesta a las dos sale de App\Services\Cobranza\CalculadoraDeSaldo, que
 * devuelve el DESGLOSE y no solo un total.
 *
 * POR QUÉ NO HAY NÚMERO DE CUOTA ACÁ
 * ----------------------------------
 * La cuota de un abono es una decisión del estudio y sale de
 * `config/fotografia.php`. No se escribe en la base porque no es un dato del
 * abono: es un dato del acuerdo. Ver docs/06.
 *
 * `numero_cuota` SÍ ESTÁ, Y ES INFORMACIÓN PARA LA PERSONA QUE ATIENDE
 * -------------------------------------------------------------------
 * Permite mostrar "tercer abono de cuatro" sin tener que derivarlo de la
 * cantidad de abonos. Es una etiqueta, no una regla: el sistema no exige que
 * existan todas las cuotas ni en ese orden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abonos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('contrato_id');
            $tabla->uuid('registrado_por')->nullable();
            $tabla->string('codigo', 32);
            $tabla->date('fecha');
            // Pesos enteros. Nunca decimal: un saldo mal redondeado se convierte
            // en una diferencia de caja que nadie sabe de dónde salió.
            $tabla->integer('monto');
            // `efectivo`, `transferencia`, `tarjeta`, `otro`.
            $tabla->string('metodo', 24);
            // Referencia del comprobante, cuando el medio la deja. Ver
            // App\Enums\MetodoAbono::dejaRastro().
            $tabla->string('referencia', 120)->nullable();
            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('estudios')->cascadeOnDelete();
            $tabla->foreign('contrato_id')->references('id')->on('contratos')->restrictOnDelete();
            $tabla->foreign('registrado_por')->references('id')->on('usuarios')->nullOnDelete();

            $tabla->unique(['tenant_id', 'codigo']);
            $tabla->index(['tenant_id', 'contrato_id']);
            $tabla->index(['tenant_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abonos');
    }
};