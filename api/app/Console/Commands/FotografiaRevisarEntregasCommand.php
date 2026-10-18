<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecordarSeleccionPendienteJob;
use App\Jobs\VerificarConservacionJob;
use App\Services\Conservacion\VerificadorDeConservacion;
use App\Services\Tenancy\ContextoEstudio;
use Illuminate\Console\Command;

/**
 * Pasa por encima del estado del estudio y deja escrita lo que hay que mirar.
 *
 * POR QUÉ ESTE COMANDO Y NO UN INFORME EN PANTALLA
 * ------------------------------------------------
 * Porque las dos preguntas que importan —"¿qué sesiones están esperando que la
 * gente elija?" y "¿de qué medios no tengo noticias?"— se tienen que poder
 * responder desde una terminal, desde un cron, y antes de que se abra el panel.
 * En una instalação chica, el dueño mira por `docker compose exec api`.
 *
 * LO QUE NO HACE
 * --------------
 * No envía correos ni mensajes: esta aplicación no tiene salida de correo
 * configurada, y mandar un aviso que nadie pidió es peor que no mandarlo. Deja el
 * dato escrito y disponible.
 *
 * Con `--avisar` encola los dos trabajos de revisión, que corren en segundo plano
 * con el contexto del estudio ya fijado. Ver docs/08.
 */
class FotografiaRevisarEntregasCommand extends Command
{
    protected $signature = 'fotografia:revisar-entregas
        {--estudio= : Identificador del estudio. Si se omite, revisa todos.}
        {--avisar : Encola los trabajos de revisión de conservación y de selección.}';

    protected $description = 'Muestra las sesiones que esperan selección y los medios con originales sin verificar.';

    public function handle(
        ContextoEstudio $contexto,
        VerificadorDeConservacion $verificador
    ): int {
        $estudios = $this->estudiosARevisar();

        if ($estudios === []) {
            $this->error('No hay estudios para revisar.');

            return self::FAILURE;
        }

        foreach ($estudios as $estudio) {
            $this->line('');
            $this->line("ESTUDIO: {$estudio->nombre} ({$estudio->slug})");

            $contexto->ejecutarPara((string) $estudio->id, function () use ($estudio, $verificador): void {
                $this->secciones($estudio, $verificador);

                if ($this->option('avisar')) {
                    VerificarConservacionJob::dispatch((string) $estudio->id);
                    RecordarSeleccionPendienteJob::dispatch((string) $estudio->id);

                    $this->line('  trabajos de revisión encolados.');
                }
            });
        }

        $this->line('');
        $this->comment('Ver docs/08-RESPALDOS.md y docs/06-PRIMER-CONTRATO.md.');

        return self::SUCCESS;
    }

    private function secciones(object $estudio, VerificadorDeConservacion $verificador): void
    {
        $informe = $verificador->informe((string) $estudio->id);

        // --- Sesiones que esperan selección --------------------------------
        $pendientesDeSeleccion = \App\Models\Sesion::query()
            ->where('estado', 'pendiente_seleccion')
            ->orderBy('fecha_prometida_entrega')
            ->get(['titulo', 'fecha_prometida', 'fecha_prometida_entrega']);

        $this->line('  Sesiones pendientes de selección: ' . $pendientesDeSeleccion->count());

        foreach ($pendientesDeSeleccion as $sesion) {
            $this->line(sprintf(
                '    - %s (fecha prometida: %s)',
                $sesion->titulo,
                $sesion->fecha_prometida_entrega?->format('d-m-Y') ?? 'sin plazo configurado'
            ));
        }

        // --- Medios con originales ----------------------------------------
        $this->line('  Medios con originales por revisar: ' . count($informe['pendientes']));

        foreach ($informe['pendientes'] as $medio) {
            $dias = $medio['dias_sin_verificar'];

            $this->line(sprintf(
                '    - %s [%s] (%s) %s',
                $medio['nombre'],
                $medio['estado_etiqueta'],
                $medio['ubicacion'],
                $dias === null ? 'nunca verificado' : ('hace ' . $dias . ' día(s)')
            ));
        }

        if (! $informe['plazo_configurado']) {
            $this->line('    NOTA: no hay plazo de verificación configurado (FOTOGRAFIA_DIAS_MAX_SIN_VERIFICAR).');
            $this->line('    El sistema no avisa solo por antigüedad; hay que mirar esta lista.');
        }

        // --- Sesiones ya entregadas cuyo medio no está operativo ----------
        $enRiesgo = $informe['sesiones_en_riesgo'];

        if ($enRiesgo !== []) {
            $this->error('  Sesiones YA ENTREGADAS con originales en un medio que no está operativo: ' . count($enRiesgo));

            foreach ($enRiesgo as $sesion) {
                $this->line(sprintf(
                    '    - %s, en %s [%s]',
                    $sesion['titulo'],
                    (string) $sesion['conservacion'],
                    (string) $sesion['estado_conservacion']
                ));
            }
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Estudio>
     */
    private function estudiosARevisar()
    {
        $slug = $this->option('estudio');

        if ($slug !== null && $slug !== '') {
            return \App\Models\Estudio::query()->where('slug', $slug)->get();
        }

        return \App\Models\Estudio::query()->where('activo', true)->orderBy('nombre')->get();
    }
}