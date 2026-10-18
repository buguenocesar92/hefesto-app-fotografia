<?php

declare(strict_types=1);

namespace Tests\Unit\Cobranza;

use App\Services\Cobranza\AbonoContado;
use App\Services\Cobranza\CobroAContar;
use App\Services\Cobranza\CalculadoraDeSaldo;
use App\Services\Cobranza\LineaAContar;
use PHPUnit\Framework\TestCase;

/**
 * El cálculo del saldo, sin base de datos.
 *
 * POR QUÉ ESTA PRUEBA NO LEVANTA POSTGRESQL
 * ----------------------------------------
 * Porque la calculadora no toca la base: recibe estructuras planas y devuelve un
 * desglose. Eso permite probarla en milisegundos y correrla cada vez que se toca
 * una línea del cálculo, que es lo que hace que sirve para lo que está.
 *
 * Si esta prueba necesitara una base, la revisión que se hace antes de tocar una cifra
 * de plata no se haría, y el cálculo quedaría sin comprobar hasta que algo se
 * rompiera. Ver `App\Services\Cobranza\CalculadoraDeSaldo`.
 */
class CalculadoraDeSaldoTest extends TestCase
{
    private CalculadoraDeSaldo $calculadora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculadora = new CalculadoraDeSaldo();
    }

    /**
     * @param  list<LineaAContar>  $lineas
     * @param  list<AbonoContado>  $abonos
     * @param  list<string>  $observaciones
     */
    private function cobro(array $lineas, array $abonos = [], int $precioContrato = 0, array $observaciones = []): CobroAContar
    {
        return new CobroAContar(
            contratoId: 'contrato-1',
            codigo: 'C-0001',
            precioContrato: $precioContrato,
            lineas: $lineas,
            abonos: $abonos,
            observaciones: $observaciones,
        );
    }

    public function test_una_linea_sin_entregar_no_tiene_saldo_a_favor(): void
    {
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [new LineaAContar('Fotos tratadas', 100, 0, 2000)],
            precioContrato: 200000,
        ));

        $this->assertSame(200000, $desglose->precioContrato());
        $this->assertSame(0, $desglose->totalEntregado());
        $this->assertSame(200000, $desglose->valorPorEntregar());
        $this->assertSame(0, $desglose->totalAbonado());
        $this->assertSame(200000, $desglose->saldoPendiente());
        $this->assertSame(0, $desglose->saldoAFavor());
        $this->assertFalse($desglose->estaTodoEntregado());
    }

    public function test_una_entrega_parcial_no_cierra_el_contrato(): void
    {
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [new LineaAContar('Fotos tratadas', 100, 20, 2000)],
            precioContrato: 200000,
        ));

        // Veinte de cien: se entregó parte, el contrato sigue abierto.
        $this->assertSame(40000, $desglose->totalEntregado());
        $this->assertSame(800, $desglose->cantidadPendiente());
        $this->assertSame(160000, $desglose->valorPorEntregar());
        $this->assertFalse($desglose->estaTodoEntregado());

        // Y esto es lo importante: entregar NO es cobrar. La deuda sigue siendo
        // el precio completo menos lo que entró.
        $this->assertSame(200000, $desglose->saldoPendiente());
    }

    public function test_un_abono_parcial_deja_el_resto_pendiente(): void
    {
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [new LineaAContar('Fotos tratadas', 100, 100, 2000)],
            abonos: [new AbonoContado('A-1', 100000, '2026-10-01', 'transferencia')],
            precioContrato: 200000,
        ));

        $this->assertTrue($desglose->estaTodoEntregado());
        $this->assertSame(100000, $desglose->totalAbonado());
        $this->assertSame(100000, $desglose->saldoPendiente());
        $this->assertSame(0, $desglose->saldoAFavor());
    }

    public function test_un_abono_de_mas_se_informa_como_saldo_a_favor_y_no_como_saldo_negativo(): void
    {
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [new LineaAContar('Fotos tratadas', 100, 100, 2000)],
            abonos: [new AbonoContado('A-1', 250000, '2026-10-01', 'efectivo')],
            precioContrato: 200000,
        ));

        // Ninguna de las dos cifras es negativa. Un saldo con signo se presta a
        // ser mal leído de reojo en la pantalla de caja.
        $this->assertSame(0, $desglose->saldoPendiente());
        $this->assertSame(50000, $desglose->saldoAFavor());
    }

    public function test_el_saldo_se_calcula_sobre_el_precio_del_contrato_y_no_sobre_lo_entregado(): void
    {
        // El día de la firma no se ha tomado ninguna foto y no se ha entregado
        // nada. El abono inicial ya entró. El sistema NO puede decir que no se
        // debe nada, porque el resto se paga al entregar.
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [new LineaAContar('Sesión de dos horas', 120, 0, 1000, 'minutos')],
            abonos: [new AbonoContado('A-1', 100000, '2026-10-01', 'transferencia')],
            precioContrato: 200000,
        ));

        $this->assertSame(0, $desglose->totalEntregado());
        $this->assertSame(100000, $desglose->saldoPendiente());
    }

    public function test_una_linea_de_sesion_se_cuenta_en_minutos(): void
    {
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [new LineaAContar('Sesión de dos horas', 120, 60, 1000, 'minutos')],
            precioContrato: 120000,
        ));

        $linea = $desglose->lineas[0];

        $this->assertSame('minutos', $linea->unidad);
        $this->assertSame(60000, $linea->entregado());
        $this->assertSame(60000, $linea->valorPendiente());
        $this->assertSame(60, $linea->cantidadPendiente());
    }

    public function test_varias_lineas_se_suman_y_el_pendiente_es_por_linea(): void
    {
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [
                new LineaAContar('Sesión de dos horas', 120, 120, 1000, 'minutos'),
                new LineaAContar('Fotos tratadas', 100, 30, 2000),
                new LineaAContar('Impresión 15x15', 30, 0, 500),
            ],
            precioContrato: 335000,
        ));

        // 120000 de sesión + 200000 de fotos + 15000 de impresión.
        $this->assertSame(120000, $desglose->totalEntregado());
        $this->assertSame(335000, $desglose->totalDeLineas());

        // 0 de sesión + 70000 de fotos + 15000 de impresión.
        $this->assertSame(85000, $desglose->valorPorEntregar());
        $this->assertCount(3, $desglose->lineas);
        $this->assertCount(2, $desglose->lineasPendientes());
    }

    public function test_una_entrega_por_sobre_lo_pactado_se_avisa_y_no_se_descuenta(): void
    {
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [new LineaAContar('Fotos tratadas', 100, 115, 2000)],
            precioContrato: 200000,
        ));

        $linea = $desglose->lineas[0];

        $this->assertTrue($linea->tieneExceso());
        $this->assertSame(15, $linea->unidadesDeExceso());

        // Lo que falta se calcula sobre lo pactado, no sobre lo entregado.
        $this->assertSame(0, $linea->valorPendiente());
        $this->assertTrue($linea->estaCompleta());

        // Y el exceso queda escrito, porque es un hecho que el estudio tiene que
        // saber que pasó.
        $this->assertNotEmpty($desglose->observaciones);
        $this->assertStringContainsString('15 unidad(es)', $desglose->observaciones[0]);
    }

    public function test_un_contrato_sin_lineas_no_se_da_por_entregado(): void
    {
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [],
            precioContrato: 100000,
        ));

        $this->assertFalse($desglose->estaTodoEntregado());
        $this->assertNotEmpty($desglose->observaciones);
        $this->assertStringContainsString('no tiene líneas', $desglose->observaciones[0]);
    }

    public function test_la_diferencia_entre_el_precio_y_la_suma_de_las_lineas_se_informa(): void
    {
        // Un descuento del 10%: el contrato dice 180000 y las líneas suman
        // 200000. El sistema NO reparte la diferencia solo: la dice.
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [new LineaAContar('Fotos tratadas', 100, 0, 2000)],
            precioContrato: 180000,
        ));

        $this->assertSame(200000, $desglose->totalDeLineas());
        $this->assertSame(180000, $desglose->precioContrato());

        $unidas = implode(' | ', $desglose->observaciones);
        $this->assertStringContainsString('20000 pesos mayor', $unidas);
    }

    public function test_las_observaciones_que_entran_se_conservan_y_no_se_repiten(): void
    {
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [new LineaAContar('Fotos tratadas', 100, 0, 2000)],
            precioContrato: 200000,
            observaciones: [
                'Hay 1 sesión(es) pendiente(s) de selección.',
                'Hay 1 sesión(es) pendiente(s) de selección.',
            ],
        ));

        $conSeleccion = array_values(array_filter(
            $desglose->observaciones,
            static fn (string $o): bool => str_contains($o, 'pendiente(s) de selección')
        ));

        $this->assertCount(1, $conSeleccion);
    }

    public function test_los_abonos_vienen_ordenados_por_fecha(): void
    {
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [new LineaAContar('Fotos tratadas', 100, 100, 2000)],
            abonos: [
                new AbonoContado('A-3', 50000, '2026-10-20', 'efectivo'),
                new AbonoContado('A-1', 50000, '2026-10-01', 'transferencia'),
                new AbonoContado('A-2', 50000, '2026-10-10', 'tarjeta'),
            ],
            precioContrato: 200000,
        ));

        $this->assertSame(['A-1', 'A-2', 'A-3'], array_map(
            static fn ($abono): string => $abono->codigo,
            $desglose->abonos
        ));

        $this->assertSame(150000, $desglose->totalAbonado());
        $this->assertSame(50000, $desglose->saldoPendiente());
    }

    public function test_el_desglose_para_la_pantalla_no_es_solo_un_total(): void
    {
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [new LineaAContar('Fotos tratadas', 100, 20, 2000)],
            abonos: [new AbonoContado('A-1', 100000, '2026-10-01', 'transferencia', dejaRastro: true)],
            precioContrato: 200000,
        ));

        $arreglo = $desglose->aArray();

        // Si esto fuera un total, estas ocho cifras no existirían.
        foreach ([
            'precio_contrato',
            'total_de_lineas',
            'total_entregado',
            'valor_por_entregar',
            'cantidad_pendiente',
            'total_abonado',
            'saldo_pendiente',
            'saldo_a_favor',
        ] as $clave) {
            $this->assertArrayHasKey($clave, $arreglo, "Falta la clave {$clave} en el desglose.");
        }

        $this->assertIsArray($arreglo['lineas']);
        $this->assertIsArray($arreglo['abonos']);
        $this->assertIsArray($arreglo['observaciones']);

        $this->assertSame(100000, $arreglo['saldo_pendiente']);
        $this->assertSame(160000, $arreglo['valor_por_entregar']);
        $this->assertSame(80, $arreglo['cantidad_pendiente']);
        $this->assertTrue($arreglo['abonos'][0]['deja_rastro']);
    }

    public function test_el_desglose_de_linea_no_tiene_cantidades_negativas(): void
    {
        $desglose = $this->calculadora->calcular($this->cobro(
            lineas: [new LineaAContar('Fotos tratadas', 100, 115, 2000)],
            precioContrato: 200000,
        ));

        $linea = $desglose->lineas[0]->aArray();

        $this->assertSame(0, $linea['cantidad_pendiente']);
        $this->assertSame(0, $linea['valor_pendiente']);
        $this->assertSame(15, $linea['unidades_de_exceso']);
    }
}