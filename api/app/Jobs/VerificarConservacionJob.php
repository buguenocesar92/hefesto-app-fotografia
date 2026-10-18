<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Conservacion\VerificadorDeConservacion;
use App\Services\Tenancy\ContextoEstudio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Revisa los medios con originales de un estudio.
 *
 * POR QUÉ UN TRABAJO Y NO UNA TAREA PROGRAMADA DIRECTA
 * ---------------------------------------------------
 * Porque el trabajo tiene que correr con el contexto del estudio ya fijado. La
 * tarea programada solo sabe qué hora es; el trabajo sabe de quién es y abre la
 * transacción con `set_config`. Ver ContextoEstudio.
 *
 * LO QUE HACE Y LO QUE NO HACE
 * ----------------------------
 * Anota los medios que hay que mirar y, sobre todo, las sesiones YA ENTREGADAS
 * cuyos originales están en un medio que no está operativo. Esa segunda lista es
 * la que duele: es una promesa que el estudio ya no puede cumplir.
 *
 * No envía correos ni mensajes: esta aplicación no tiene salida de correo
 * configurada, y mandar un aviso que nadie pidió es peor que no mandarlo. Lo
 * que hace es dejar el dato escrito y disponible. Ver docs/08.
 */
class VerificarConservacionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $estudioId) {}

    /**
     * La tarea se programa con este grupo.
     *
     * El `max-time` hace que el worker muera y vuelva. Es lo que evita que
     * arrastre el `app.tenant_id` de una transacción a la siguiente. Ver docs/03.
     */
    public function tags(): array
    {
        return ['fotografia', 'conservacion', 'estudio:' . $this->estudioId];
    }

    public function handle(ContextoEstudio $contexto, VerificadorDeConservacion $verificador): void
    {
        $contexto->ejecutarPara($this->estudioId, function () use ($verificador): void {
            $informe = $verificador->informe($this->estudioId);

            $pendientes = count($informe['pendientes']);
            $enRiesgo = count($informe['sesiones_en_riesgo']);

            Log::info('revision de conservaciones', [
                'estudio' => $this->estudioId,
                'medios_por_revisar' => $pendientes,
                'sesiones_en_riesgo' => $enRiesgo,
                'plazo_configurado' => $informe['plazo_configurado'],
            ]);
        });
    }
}