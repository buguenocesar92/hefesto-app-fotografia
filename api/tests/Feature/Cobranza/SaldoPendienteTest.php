<?php

declare(strict_types=1);

namespace Tests\Feature\Cobranza;

use App\Enums\MetodoAbono;
use App\Enums\TipoLineaContrato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El saldo pendiente, leído desde la base.
 *
 * POR QUÉ HAY UNA PRUEBA DE FUNCIÓN Y OTRA DE UNIDAD
 * -------------------------------------------------
 * La de unidad comprueba la aritmética sin tocar PostgreSQL
 * (`tests/Unit/Cobranza/CalculadoraDeSaldoTest.php`). Esta comprueba lo que solo
 * se puede ver con la base: que el saldo lee de las líneas correctas, que los
 * abonos se suman todos y que las observaciones sobre sesiones y laboratorios
 * aparecen de verdad.
 *
 * Las dos hacen falta. La aritmética puede estar bien y leer las tablas
 * equivocadas; y las tablas pueden estar bien y la aritmética estar mal.
 *
 * EN ESTA MÁQUINA NO SE EJECUTARON
 * ------------------------------
 * En el equipo donde se escribió esta aplicación no hay PHP, Composer, Docker ni
 * PostgreSQL. Estas pruebas NO se corrieron. Ver docs/10.
 */
class SaldoPendienteTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_saldo_de_un_contrato_firmado_sin_abono_es_el_precio_completo(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente, 200000);

        $desglose = $this->dentroDe($usuario, fn () => $contrato->saldo());

        $this->assertSame(200000, $desglose->precioContrato());
        $this->assertSame(0, $desglose->totalAbonado());
        $this->assertSame(200000, $desglose->saldoPendiente());
        $this->assertSame(0, $desglose->saldoAFavor());
        $this->assertSame(0, $desglose->totalEntregado());
        $this->assertSame(200000, $desglose->valorPorEntregar());
    }

    public function test_el_abono_inicial_deja_el_resto_pendiente(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente, 200000);

        // El cincuenta por ciento al contratar, que es la forma descrita en el
        // requerimiento. Lo que se cobre de verdad lo declara el estudio en
        // config/fotografia.php.
        $this->crearAbono($estudio, $contrato, 100000, MetodoAbono::Transferencia);

        $desglose = $this->dentroDe($usuario, fn () => $contrato->saldo());

        $this->assertSame(100000, $desglose->totalAbonado());
        $this->assertSame(100000, $desglose->saldoPendiente());
    }

    public function test_varios_abonos_se_suman_y_el_ultimo_deja_el_saldo_en_cero(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente, 200000);

        $this->crearAbono($estudio, $contrato, 50000, MetodoAbono::Efectivo);
        $this->crearAbono($estudio, $contrato, 80000, MetodoAbono::Transferencia);
        $this->crearAbono($estudio, $contrato, 70000, MetodoAbono::Tarjeta);

        $desglose = $this->dentroDe($usuario, fn () => $contrato->saldo());

        $this->assertSame(200000, $desglose->totalAbonado());
        $this->assertSame(0, $desglose->saldoPendiente());
        $this->assertSame(0, $desglose->saldoAFavor());
        $this->assertFalse($desglose->tieneSaldo());
        $this->assertCount(3, $desglose->abonos);
    }

    public function test_una_sesion_pendiente_de_seleccion_aparece_como_observacion(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente);

        $this->crearSesion($estudio, $cliente, \App\Enums\EstadoSesion::PendienteSeleccion);

        $desglose = $this->dentroDe($usuario, fn () => $contrato->saldo());

        $unidas = implode(' | ', $desglose->observaciones);

        $this->assertStringContainsString('pendiente(s) de selección', $unidas);

        // Y dice POR QUÉ no se puede avanzar: es lo que contesta por qué la línea
        // de impresión sigue en cero.
        $this->assertStringContainsString('no hay nada que imprimir', $unidas);
    }

    public function test_una_sesion_pendiente_de_seleccion_no_altera_la_deuda(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente, 200000);

        $sinSesiones = $this->dentroDe($usuario, fn () => $contrato->saldo());

        $this->crearSesion($estudio, $cliente, \App\Enums\EstadoSesion::PendienteSeleccion);

        $conSesiones = $this->dentroDe($usuario, fn () => $contrato->saldo());

        // Esperar una decisión de la persona es un hecho del mundo, no una deuda.
        $this->assertSame(
            $sinSesiones->saldoPendiente(),
            $conSesiones->saldoPendiente(),
            'Estar pendiente de selección cambió el saldo. No debería: no es plata.'
        );
    }

    public function test_el_saldo_de_un_contrato_con_varias_lineas_es_la_suma_de_ellas(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente, 335000, [
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
            [
                'tipo' => TipoLineaContrato::Impresion->value,
                'concepto' => 'Impresión 15x15',
                'cantidad_pactada' => 30,
                'precio_unitario' => 500,
            ],
        ]);

        $desglose = $this->dentroDe($usuario, fn () => $contrato->saldo());

        $this->assertSame(3, count($desglose->lineas));
        $this->assertSame(335000, $desglose->totalDeLineas());
        $this->assertSame(335000, $desglose->valorPorEntregar());
        $this->assertSame(250, $desglose->cantidadPendiente());
    }

    public function test_el_desglose_llega_completo_a_la_pantalla(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente, 200000);
        $this->crearAbono($estudio, $contrato, 60000);

        $arreglo = $this->dentroDe($usuario, fn (): array => $contrato->saldo()->aArray());

        // Lo que la pantalla necesita para no tener que inventar nada.
        $this->assertSame(200000, $arreglo['precio_contrato']);
        $this->assertSame(60000, $arreglo['total_abonado']);
        $this->assertSame(140000, $arreglo['saldo_pendiente']);
        $this->assertSame(0, $arreglo['saldo_a_favor']);
        $this->assertArrayHasKey('lineas', $arreglo);
        $this->assertArrayHasKey('abonos', $arreglo);
        $this->assertArrayHasKey('observaciones', $arreglo);
        $this->assertCount(1, $arreglo['abonos']);
    }

    public function test_un_abono_de_mas_no_produce_un_saldo_negativo(): void
    {
        $estudio = $this->crearEstudio();
        $usuario = $this->crearUsuario($estudio);
        $cliente = $this->crearCliente($estudio);

        $contrato = $this->crearContrato($estudio, $cliente, 200000);
        $this->crearAbono($estudio, $contrato, 250000);

        $desglose = $this->dentroDe($usuario, fn () => $contrato->saldo());

        $this->assertSame(0, $desglose->saldoPendiente());
        $this->assertSame(50000, $desglose->saldoAFavor());
    }
}