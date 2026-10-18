<template>
    <div>
        <h2>Saldos</h2>
        <p class="tenue">
            Un saldo es un desglose, no un total. El estudio hace tres preguntas distintas —qué
            prometí, qué entregué y cuánto me deben— y una sola cifra no contesta ninguna de las tres.
        </p>

        <div v-if="mensaje" class="aviso aviso-ok" role="status">{{ mensaje }}</div>
        <div v-if="error" class="aviso aviso-error" role="alert">{{ error }}</div>

        <p v-if="cargando" class="cargando">Cargando los saldos...</p>

        <template v-else>
            <section class="tarjeta">
                <h3>Totales del estudio</h3>
                <dl v-if="totales" class="desglose">
                    <div class="cifra">
                        <dt>Entregado</dt>
                        <dd>{{ formatear(totales.entregado) }}</dd>
                    </div>
                    <div class="cifra">
                        <dt>Falta entregar</dt>
                        <dd>{{ formatear(totales.por_entregar) }}</dd>
                    </div>
                    <div class="cifra">
                        <dt>Abonado</dt>
                        <dd>{{ formatear(totales.abonado) }}</dd>
                    </div>
                    <div class="cifra" :class="{ 'cifra-alerta': totales.por_cobrar > 0 }">
                        <dt>Por cobrar</dt>
                        <dd>{{ formatear(totales.por_cobrar) }}</dd>
                    </div>
                </dl>
            </section>

            <p v-if="filas.length === 0" class="vacio">
                No hay contratos abiertos con saldo.
            </p>

            <table v-else>
                <thead>
                    <tr>
                        <th scope="col">Contrato</th>
                        <th scope="col">Prometido</th>
                        <th scope="col">Entregado</th>
                        <th scope="col">Falta entregar</th>
                        <th scope="col">Abonado</th>
                        <th scope="col">Saldo</th>
                        <th scope="col">Observaciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="fila in filas" :key="fila.contrato_id">
                        <td>
                            {{ fila.codigo }}
                            <span class="tenue"> · {{ fila.cliente }}</span>
                        </td>
                        <td>{{ formatear(fila.precio_contrato) }}</td>
                        <td>{{ formatear(fila.total_entregado) }}</td>
                        <td>
                            {{ formatear(fila.valor_por_entregar) }}
                            <span class="tenue">({{ fila.cantidad_pendiente }} ud.)</span>
                        </td>
                        <td>{{ formatear(fila.total_abonado) }}</td>
                        <td>
                            <strong :class="{ 'estado-vencido': fila.saldo_pendiente > 0 }">
                                {{ formatear(fila.saldo_pendiente) }}
                            </strong>
                            <div v-if="fila.saldo_a_favor > 0" class="tenue">
                                Pagado de más: {{ formatear(fila.saldo_a_favor) }}
                            </div>
                        </td>
                        <td class="tenue">
                            <ul v-if="fila.observaciones.length">
                                <li v-for="observacion in fila.observaciones" :key="observacion">
                                    {{ observacion }}
                                </li>
                            </ul>
                            <span v-else>—</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!--
                El abono. La política sale de la configuración del estudio y
                aquí se declara, para que quien atiende sepa si el abono inicial
                es obligatorio y si se admite un abono parcial. No hay un
                porcentaje escrito en la pantalla: ese número es del estudio.
            -->
            <section v-if="politica" class="tarjeta">
                <h3>Registrar un abono</h3>

                <p class="tenue">
                    La política de esta instalación: abono inicial de
                    {{ politica.porcentaje_abono_inicial }}% del contrato
                    {{ politica.exigir_abono_inicial ? '(obligatorio)' : '(opcional)' }}, y
                    {{ politica.permitir_abono_parcial ? 'se admiten abonos parciales' : 'el abono debe cubrir el monto completo' }}.
                </p>

                <form class="columnas" @submit.prevent="registrar">
                    <div class="campo">
                        <label for="abono-contrato">Contrato</label>
                        <select id="abono-contrato" v-model="formulario.contrato_id" required>
                            <option value="">Elija un contrato</option>
                            <option v-for="fila in filas" :key="fila.contrato_id" :value="fila.contrato_id">
                                {{ fila.codigo }} — {{ fila.cliente }}
                            </option>
                        </select>
                    </div>
                    <div class="campo">
                        <label for="abono-codigo">Código del abono</label>
                        <input id="abono-codigo" v-model="formulario.codigo" required maxlength="32" />
                    </div>
                    <div class="campo">
                        <label for="abono-monto">Monto</label>
                        <input id="abono-monto" v-model.number="formulario.monto" type="number" min="1" required />
                    </div>
                    <div class="campo">
                        <label for="abono-fecha">Fecha</label>
                        <input id="abono-fecha" v-model="formulario.fecha" type="date" required />
                    </div>
                    <div class="campo">
                        <label for="abono-metodo">Método de pago</label>
                        <select id="abono-metodo" v-model="formulario.metodo" required>
                            <option v-for="metodo in metodos" :key="metodo.valor" :value="metodo.valor">
                                {{ metodo.etiqueta }}
                            </option>
                        </select>
                        <p v-if="metodoDejaRastro" class="tenue">
                            Este método deja un comprobante: anote la referencia para poder
                            contrastar después.
                        </p>
                    </div>
                    <div class="campo">
                        <label for="abono-referencia">Referencia</label>
                        <input id="abono-referencia" v-model="formulario.referencia" maxlength="120" />
                    </div>

                    <div>
                        <button type="submit" class="boton" :disabled="enviando || !puedeAbonar">
                            {{ enviando ? 'Registrando...' : 'Registrar abono' }}
                    </button>
                    </div>
                </form>
            </section>
        </template>
    </div>
</template>

<script setup lang="ts">
import type { FilaSaldo } from '~/tipos/api'

/**
 * Saldos y abonos.
 *
 * POR QUE ESTA PANTALLA MUESTRA OBSERVACIONES Y NO SOLO CIFRAS
 * -------------------------------------------------------------
 * Porque un saldo sin explicación obliga a llamar por teléfono para entenderlo. Las
 * observaciones dicen, en palabras, lo que está frenando la entrega: una sesión
 * esperando selección, un pedido de laboratorio vencido, una línea entregada de
 * más. Vienen del servicio de saldo, no de esta pantalla.
 *
 * POR QUÉ LA POLÍTICA DE ABONO SE MUESTRA Y NO SE CALCULA
 * ------------------------------------------------------
 * El porcentaje de abono inicial es del estudio y sale de
 * `config('fotografia.abonos.porcentaje_abono_inicial')`. Esta pantalla lo muestra
 * para que quien atiende sepa si el abono inicial es obligatorio; no lo aplica
 * sola, porque aplicar una regla comercial en el navegador es una segunda fuente
 * de verdad. Ver docs/06.
 */

definePageMeta({
    middleware: 'auth',
})

interface MetodoPago {
    valor: string
    etiqueta: string
    deja_rastro: boolean
}

const { puedeRegistrarAbonos } = useSesion()
const puedeAbonar = puedeRegistrarAbonos

const cargando = ref(true)
const enviando = ref(false)
const filas = ref<FilaSaldo[]>([])
const totales = ref<{
    entregado: number
    por_entregar: number
    abonado: number
    por_cobrar: number
} | null>(null)
const metodos = ref<MetodoPago[]>([])
const politica = ref<{
    porcentaje_abono_inicial: number
    exigir_abono_inicial: boolean
    permitir_abono_parcial: boolean
} | null>(null)
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)

const formulario = reactive({
    contrato_id: '',
    codigo: '',
    monto: null as number | null,
    fecha: new Date().toISOString().slice(0, 10),
    metodo: 'efectivo',
    referencia: '',
})

const metodoDejaRastro = computed(
    () => metodos.value.find((metodo) => metodo.valor === formulario.metodo)?.deja_rastro ?? false,
)

async function registrar() {
    enviando.value = true
    mensaje.value = null
    error.value = null

    try {
        const respuesta = await $fetch<{ mensaje: string }>(
            `/api/saldos/${formulario.contrato_id}/abonos`,
            {
                method: 'POST',
                body: {
                    codigo: formulario.codigo,
                    monto: formulario.monto,
                    fecha: formulario.fecha,
                    metodo: formulario.metodo,
                    referencia: formulario.referencia === '' ? null : formulario.referencia,
                },
            },
        )

        mensaje.value = respuesta.mensaje
        formulario.codigo = ''
        formulario.monto = null
        formulario.referencia = ''

        await cargar()
    } catch (excepcion) {
        error.value = mensajeDe(excepcion)
    } finally {
        enviando.value = false
    }
}

async function cargar() {
    cargando.value = true

    try {
        const [saldos, metodosPago] = await Promise.all([
            $fetch<{ saldos: FilaSaldo[]; totales: NonNullable<typeof totales.value> }>('/api/saldos'),
            $fetch<{ metodos: MetodoPago[]; politica_de_abono: NonNullable<typeof politica.value> }>(
                '/api/saldos/metodos',
            ),
        ])

        filas.value = saldos.saldos
        totales.value = saldos.totales
        metodos.value = metodosPago.metodos
        politica.value = metodosPago.politica_de_abono
    } finally {
        cargando.value = false
    }
}

function mensajeDe(excepcion: unknown): string {
    const cuerpo = (excepcion as { data?: { mensaje?: string } })?.data

    return cuerpo?.mensaje ?? 'No se pudo completar la operación.'
}

function formatear(monto: number): string {
    return new Intl.NumberFormat('es-CL').format(monto)
}

await cargar()
</script>