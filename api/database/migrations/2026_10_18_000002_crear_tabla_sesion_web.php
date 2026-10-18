<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sesion_web`: dónde vive la cookie de sesión, y `password_reset_tokens`.
 *
 * POR QUÉ ESTA TABLA SE LLAMA `sesion_web` Y NO `sesiones`
 * -------------------------------------------------------
 * En el dominio de la fotografía, `sesiones` son las tomas con fecha prometida:
 * el cumple, el matrimonio, el bautismo. Es la palabra que usa el estudio.
 *
 * La tabla de sesión de Laravel no tiene por qué llamarse igual. Cambiar su
 * nombre (en `config/session.php` y acá) es lo que permite que la tabla del
 * dominio se llame `sesiones` y se lea como lo que es en cada consulta.
 *
 * POR QUÉ NO LLEVA RLS
 * --------------------
 * Porque se consulta ANTES de saber a qué estudio pertenece la persona: el guard
 * `web` la necesita para resolver el usuario, y en ese instante todavía no hay
 * `app.tenant_id`. Con RLS, nadie podría iniciar sesión.
 *
 * Es la misma excepción que `estudios`, y por la misma razón. La fila contiene un
 * identificador de sesión y el identificador de usuario, que es lo que hay
 * dentro. El contenido de la sesión no va en la base: viaja en la cookie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesion_web', function (Blueprint $tabla): void {
            $tabla->string('id')->primary();
            $tabla->uuid('user_id')->nullable();
            $tabla->string('ip_address', 45)->nullable();
            $tabla->text('user_agent')->nullable();
            $tabla->text('payload')->nullable();
            $tabla->integer('last_activity')->index();
        });

        Schema::create('password_reset_tokens', function (Blueprint $tabla): void {
            $tabla->string('email')->primary();
            $tabla->string('token');
            $tabla->timestampTz('created_at')->nullable();
        });

        Schema::create('cache', function (Blueprint $tabla): void {
            $tabla->string('key')->primary();
            $tabla->mediumText('value');
            $tabla->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $tabla): void {
            $tabla->string('key')->primary();
            $tabla->string('owner');
            $tabla->integer('expiration');
        });

        Schema::create('jobs', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('queue')->index();
            $tabla->longText('payload');
            $tabla->unsignedTinyInteger('attempts');
            $tabla->unsignedInteger('reserved_at')->nullable();
            $tabla->unsignedInteger('available_at');
            $tabla->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $tabla): void {
            $tabla->string('id')->primary();
            $tabla->string('name');
            $tabla->integer('total_jobs');
            $tabla->integer('pending_jobs');
            $tabla->integer('failed_jobs');
            $tabla->longText('failed_job_ids');
            $tabla->mediumText('options')->nullable();
            $tabla->integer('cancelled_at')->nullable();
            $tabla->integer('created_at');
            $tabla->integer('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sesion_web');
    }
};