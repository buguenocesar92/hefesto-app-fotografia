<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EstadoSesion;
use App\Services\Tenancy\ContextoEstudio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Anota las sesiones que están esperando que la persona elija.
 *
 * POR QUÉ ESTE TRABAJO NO AVISA A NADIE
 * ------------------------------------
 * Porque `dias_para_seleccionar` nace en `null`: no se pudo verificar un plazo
 * que sirva para todo el rubro. Con `null`, este trabajo no tiene un número con el
 * que juzgar a nadie, y lo único que puede hacer es dejar el dato escrito.
 *
 * Cuando el estudio configure un plazo, el trabajo pasa a informar cuántos días
 * lleva esperando cada sesión. Ver config/fotografia.php y docs/06.
 *
 * `pendiente_seleccion` NO ES UNA FALLA
 * -------------------------------------
 * Es un estado de trabajo normal. Por eso el trabajo registra un recuento y no
 * dispara una alerta: un sistema que trata como error algo que pasa todos los
 * días enseña a ignorar la pantalla.
 */
class RecordarSeleccionPendienteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $estudioId) {}

    public function tags(): array
    {
        return ['fotografia', 'seleccion', 'estudio:' . $this->estudioId];
    }

    public function handle(ContextoEstudio $contexto): void
    {
        $contexto->ejecutarPara($this->estudioId, function (): void {
            $pendientes = \App\Models\Sesion::query()
                ->where('estado', EstadoSesion::PendienteSeleccion->value)
                ->orderBy('fecha_prometida_entrega')
                ->get(['id', 'titulo', 'fecha_prometida', 'fecha_prometida_entrega']);

            if ($pendientes->isEmpty()) {
                Log::info('no hay sesiones pendientes de seleccion', ['estudio' => $this->estudioId]);

                return;
            }

            $dias = config('fotografia.seleccion.dias_para_seleccionar');

            Log::info('sesiones pendientes de seleccion', [
                'estudio' => $this->estudioId,
                'cantidad' => $pendientes->count(),
                'plazo_configurado' => $dias !== null,
                'dias' => $dias,
            ]);
        });
    }
}