<?php

declare(strict_types=1);

namespace Tests\Feature\Contratos;

use App\Enums\EstadoContrato;
use App\Enums\MetodoAbono;
use App\Enums\TipoLineaContrato;
use App\Models\Contrato;
use App\Services\Contratos\RegistradorDeEntrega;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Una entrega parcial NO cierra el contrato.
 *
 * POR QUÉ ESTA PRUEBA ES UNA DE LAS MÁS IMPORTANTES DEL CONJUNTO
 * -----------------------------------------------------------
 * Lo que un estudio vende es un paquete que todavía no existe cuando se contrata.
 * Si entregar veinte fotos de cien cerrara el contrato, el estudio entregaría el
 * trabajo por la mitad sin enterarse y no sabría qué falta. Es el error que hace
 * perder plata sin que nadie lo note hasta que la persona reclama.
 *
 * EN ESTA MÁQUINA NO SE EJECUTARON
 * ------------------------------
 * En el equipo donde se escribió esta aplicación no hay PHP, Composer, Docker ni
 * PostgreSQL. Estas pruebas NO se corrieron. Ver docs/10.
 */
class EntregaParcialNoCierraContratoTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_entrega_parcial_deja_el_contrato_activo(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente, 200000, [[
            'tipo' => TipoLineaContrato::FotoEntregada->value,
            'concepto' => 'Fotos tratadas',
            'cantidad_pactada' => 100,
            'precio_unitario' => 2000,
        ]]);

        $linea = $this->primeraLinea($contrato);

        $registrador = app(RegistradorDeEntrega::class);

        $this->dentroDe($usuario, function () use ($registrador, $contrato, $linea): void {
            $registrador->registrar($contrato, $linea, 20);
        });

        $this->dentroDe($usuario, function () use ($contrato, $linea): void {
            $contrato->refresh();
            $linea->refresh();

            $this->assertSame(20, $linea->cantidad_entregada);
            $this->assertSame(80, $linea->cantidadPendiente());

            // ESTA ES LA AFIRMACIÓN DE LA PRUEBA.
            $this->assertSame(
                EstadoContrato::Activo,
                $contrato->estado,
                'Entregar 20 de 100 cerró el contrato. Una entrega parcial no cierra.'
            );
            $this->assertNull($contrato->fecha_cierre);
        });
    }

    public function test_entregar_todas_las_lineas_cierra_el_contrato(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente, 230000, [
            [
                'tipo' => TipoLineaContrato::Sesion->value,
                'concepto' => 'Sesión de dos horas',
                'unidad_minutos' => 120,
                'cantidad_pactada' => 120,
                'precio_unitario' => 1000,
            ],
            [
                'tipo' => TipoLineaContrato::FotoEntregada->value,
                'concepto' => 'Fotos tratadas',
                'cantidad_pactada' => 100,
                'precio_unitario' => 2000,
            ],
        ]);

        $lineas = $contrato->lineas()->get()->keyBy('tipo');

        $registrador = app(RegistradorDeEntrega::class);

        // La primera línea completa NO cierra: la segunda sigue debiendo.
        $this->dentroDe($usuario, function () use ($registrador, $contrato, $lineas): void {
            $registrador->registrar($contrato, $lineas->get(\App\Enums\TipoLineaContrato::Sesion->value), 120);
        });

        $this->dentroDe($usuario, function () use ($contrato): void {
            $this->assertSame(EstadoContrato::Activo, $contrato->refresh()->estado);
        });

        // Ahora sí: las dos completas.
        $this->dentroDe($usuario, function () use ($registrador, $contrato, $lineas): void {
            $registrador->registrar($contrato, $lineas->get(\App\Enums\TipoLineaContrato::FotoEntregada->value), 100);
        });

        $this->dentroDe($usuario, function () use ($contrato): void {
            $contrato->refresh();

            $this->assertSame(EstadoContrato::Cerrado, $contrato->estado);
            $this->assertNotNull($contrato->fecha_cierre);
        });
    }

    public function test_no_se_puede_registrar_una_entrega_mayor_que_lo_pactado(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente, 200000, [[
            'tipo' => TipoLineaContrato::FotoEntregada->value,
            'concepto' => 'Fotos tratadas',
            'cantidad_pactada' => 100,
            'precio_unitario' => 2000,
        ]]);

        $linea = $this->primeraLinea($contrato);

        $fallo = null;

        $this->dentroDe($usuario, function () use ($contrato, $linea, &$fallo): void {
            try {
                app(RegistradorDeEntrega::class)->registrar($contrato, $linea, 150);
            } catch (\DomainException $e) {
                $fallo = $e->getMessage();
            }
        });

        $this->assertNotNull($fallo, 'Se registró una entrega mayor que lo pactado.');
        $this->assertStringContainsString('superaría lo pactado', (string) $fallo);

        $this->dentroDe($usuario, function () use ($linea): void {
            $this->assertSame(0, $linea->refresh()->cantidad_entregada);
        });
    }

    public function test_una_linea_de_impresion_no_se_entrega_sin_seleccion_confirmada(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente, 100000, [[
            'tipo' => TipoLineaContrato::Impresion->value,
            'concepto' => 'Impresión 15x15',
            'cantidad_pactada' => 30,
            'precio_unitario' => 500,
        ]]);

        $linea = $this->primeraLinea($contrato);

        $fallo = null;

        $this->dentroDe($usuario, function () use ($contrato, $linea, &$fallo): void {
            try {
                app(RegistradorDeEntrega::class)->registrar($contrato, $linea, 30);
            } catch (\DomainException $e) {
                $fallo = $e->getMessage();
            }
        });

        $this->assertNotNull(
            $fallo,
            'Se dio por entregada una línea de impresión sin ninguna selección confirmada.'
        );
        $this->assertStringContainsString('selección confirmada', (string) $fallo);
    }

    public function test_el_saldo_refleja_que_la_entrega_parcial_no_es_el_pago(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente, 200000);

        $linea = $this->primeraLinea($contrato);

        $registrador = app(RegistradorDeEntrega::class);

        $this->dentroDe($usuario, function () use ($registrador, $contrato, $linea): void {
            $registrador->registrar($contrato, $linea, 20);
        });

        // Abonaron la mitad al contratar, que es lo habitual.
        $this->crearAbono($estudio, $contrato, 100000, MetodoAbono::Transferencia);

        $desglose = $this->dentroDe($usuario, fn (): \App\Services\Cobranza\DesgloseDeSaldo => $contrato->saldo());

        $this->assertSame(200000, $desglose->precioContrato());
        $this->assertSame(40000, $desglose->totalEntregado());
        $this->assertSame(160000, $desglose->valorPorEntregar());
        $this->assertSame(100000, $desglose->totalAbonado());

        // Entregar veinte fotos no reduce la deuda: el precio se firmó entero.
        $this->assertSame(100000, $desglose->saldoPendiente());
        $this->assertFalse($desglose->estaTodoEntregado());
    }

    public function test_no_se_puede_registrar_una_entrega_en_un_contrato_que_no_esta_activo(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente);

        $this->dentroDe($usuario, function () use ($contrato): void {
            $contrato->estado = EstadoContrato::Anulado;
            $contrato->save();
        });

        $linea = $this->primeraLinea($contrato);

        $fallo = null;

        $this->dentroDe($usuario, function () use ($contrato, $linea, &$fallo): void {
            try {
                app(RegistradorDeEntrega::class)->registrar($contrato, $linea, 10);
            } catch (\DomainException $e) {
                $fallo = $e->getMessage();
            }
        });

        $this->assertNotNull($fallo);
        $this->assertStringContainsString('anulado', strtolower((string) $fallo));
    }
}