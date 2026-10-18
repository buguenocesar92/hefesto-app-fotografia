<?php

declare(strict_types=1);

namespace Tests\Feature\Sesiones;

use App\Enums\EstadoSesion;
use App\Services\Sesiones\GestorDeEstadoDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No se marca una sesión como entregada sin selección confirmada.
 *
 * POR QUÉ ESTA ES LA REGLA QUE MÁS PLATA PROTEGE
 * ----------------------------------------------
 * "Pendiente de selección" es el estado NORMAL de una sesión que se tomó y en la
 * que la persona todavía no eligió. El sistema no lo trata como un error.
 *
 * De ahí sale la regla: `entregada` exige una selección `confirmada`. Sin ella no
 * hay nada que imprimir, y lo que se imprime se paga por adelantado a un
 * laboratorio externo y no se devuelve.
 *
 * `pendiente_seleccion` NO ES UN ERROR, Y ESTA PRUEBA LO COMPRUEBA TAMBIÉN
 * -----------------------------------------------------------------------
 * Un sistema que tratara ese estado como falla enseñaría al equipo a ignorar la
 * pantalla, que es como se pierde una fecha prometida.
 *
 * EN ESTA MÁQUINA NO SE EJECUTARON
 * ------------------------------
 * En el equipo donde se escribió esta aplicación no hay PHP, Composer, Docker ni
 * PostgreSQL. Estas pruebas NO se corrieron. Ver docs/10.
 */
class NoSeMarcaEntregadaSinSeleccionTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_se_puede_entregar_una_sesion_sin_seleccion_confirmada(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $sesion = $this->crearSesion($estudio, $cliente, EstadoSesion::PendienteSeleccion);

        $fallo = null;

        $this->dentroDe($usuario, function () use ($sesion, &$fallo): void {
            try {
                app(GestorDeEstadoDeSesion::class)->moverA($sesion, EstadoSesion::Entregada);
            } catch (\DomainException $e) {
                $fallo = $e->getMessage();
            }
        });

        $this->assertNotNull(
            $fallo,
            'Se marcó como entregada una sesión sin ninguna selección confirmada.'
        );
        $this->assertStringContainsString('No hay ninguna selección registrada', (string) $fallo);

        $this->dentroDe($usuario, function () use ($sesion): void {
            $this->assertSame(EstadoSesion::PendienteSeleccion, $sesion->refresh()->estado);
        });
    }

    public function test_una_seleccion_pendiente_no_alcanza_para_entregar(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        // Se crea la selección pero NO confirmada.
        $sesion = $this->crearSesion($estudio, $cliente, EstadoSesion::PendienteSeleccion);

        $this->dentroDe($usuario, function () use ($sesion, $cliente): void {
            $seleccion = $sesion->seleccion()->create([
                'tenant_id' => $sesion->tenant_id,
                'cliente_id' => $cliente->id,
                'estado' => 'en_curso',
                'cantidad_seleccionada' => 2,
            ]);

            foreach ($sesion->fotos()->take(2)->get() as $foto) {
                $seleccion->detalles()->create([
                    'tenant_id' => $sesion->tenant_id,
                    'foto_id' => $foto->id,
                    'elegida' => true,
                ]);
            }
        });

        $fallo = null;

        $this->dentroDe($usuario, function () use ($sesion, &$fallo): void {
            try {
                app(GestorDeEstadoDeSesion::class)->moverA($sesion, EstadoSesion::Entregada);
            } catch (\DomainException $e) {
                $fallo = $e->getMessage();
            }
        });

        $this->assertNotNull($fallo, 'Una selección sin confirmar alcanzó para entregar.');
        $this->assertStringContainsString('no está confirmada', (string) $fallo);
    }

    public function test_con_seleccion_confirmada_si_se_puede_entregar(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $sesion = $this->crearSesion(
            $estudio,
            $cliente,
            EstadoSesion::PendienteSeleccion,
            seleccionConfirmada: true
        );

        $this->dentroDe($usuario, function () use ($sesion): void {
            app(GestorDeEstadoDeSesion::class)->moverA($sesion, EstadoSesion::Entregada);

            $this->assertSame(EstadoSesion::Entregada, $sesion->refresh()->estado);
        });
    }

    public function test_pendiente_de_seleccion_es_un_estado_normal_y_no_un_error(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $sesion = $this->crearSesion($estudio, $cliente, EstadoSesion::PendienteSeleccion);

        $this->dentroDe($usuario, function () use ($sesion): void {
            $sesion->refresh();

            // No es un error: tiene etiqueta, se puede consultar y se puede dejar
            // así todo el tiempo que haga falta.
            $this->assertTrue($sesion->esperaSeleccion());
            $this->assertTrue($sesion->estado->esperaDecisionDeLaPersona());
            $this->assertSame('Pendiente de selección', $sesion->estado->etiqueta());
            $this->assertNull($sesion->tieneSeleccionConfirmada());
        });
    }

    public function test_la_transicion_imposible_se_rechaza_con_un_mensaje_legible(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $sesion = $this->crearSesion($estudio, $cliente, EstadoSesion::Agendada);

        $fallo = null;

        $this->dentroDe($usuario, function () use ($sesion, &$fallo): void {
            try {
                // De `agendada` a `entregada` no hay transición: alguien tiene que
                // tomar las fotos y elegir, aunque sea de a uno en uno.
                app(GestorDeEstadoDeSesion::class)->moverA($sesion, EstadoSesion::Entregada);
            } catch (\DomainException $e) {
                $fallo = $e->getMessage();
            }
        });

        $this->assertNotNull($fallo);
        $this->assertStringContainsString('no puede pasar a', (string) $fallo);
    }

    public function test_la_pantalla_sabe_por_que_no_se_puede_entregar(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $sesion = $this->crearSesion($estudio, $cliente, EstadoSesion::PendienteSeleccion);

        // El motivo se devuelve ANTES de que la persona apriete el botón, y es la
        // misma comprobación que hace el servidor. Ocultar el botón sin explicar
        // el por qué deja a la persona apretando un botón muerto.
        $motivo = $this->dentroDe(
            $usuario,
            static fn (): ?string => app(GestorDeEstadoDeSesion::class)->motivoDeNoPoderEntregar($sesion)
        );

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('selección', (string) $motivo);
    }

    public function test_marcar_realizada_exige_la_fecha_en_que_se_tomo(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $sesion = $this->crearSesion($estudio, $cliente, EstadoSesion::Agendada);

        $fallo = null;

        $this->dentroDe($usuario, function () use ($sesion, &$fallo): void {
            try {
                app(GestorDeEstadoDeSesion::class)->moverA($sesion, EstadoSesion::Realizada);
            } catch (\DomainException $e) {
                $fallo = $e->getMessage();
            }
        });

        $this->assertNotNull($fallo, 'Se marcó como realizada sin decir qué día se tomó.');
    }
}