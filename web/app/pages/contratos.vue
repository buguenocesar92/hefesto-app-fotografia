<template>
    <div>
        <h2>Contratos con paquete</h2>
        <p class="tenue">
            Lo que el estudio vendió y todavía no entregó. El precio queda congelado en la firma:
            cambiar el precio del paquete después no cambia lo que firmó este cliente.
        </p>

        <div class="tarjeta">
            <div class="campo">
                <label for="estado">Estado</label>
                <select id="estado" v-model="estado" @change="cargar">
                    <option value="">Todos</option>
                    <option value="borrador">Borrador</option>
                    <option value="activo">Activo</option>
                    <option value="cerrado">Cerrado</option>
                    <option value="anulado">Anulado</option>
                </select>
            </div>
        </div>

        <p v-if="cargando" class="cargando">Cargando los contratos...</p>

        <p v-else-if="contratos.length === 0" class="vacio">
            No hay contratos en ese estado.
        </p>

        <table v-else>
            <thead>
                <tr>
                    <th scope="col">Código</th>
                    <th scope="col">Persona</th>
                    <th scope="col">Fecha</th>
                    <th scope="col">Precio</th>
                    <th scope="col">Líneas</th>
                    <th scope="col">Estado</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="contrato in contratos" :key="contrato.id">
                    <td>{{ contrato.codigo }}</td>
                    <td>{{ contrato.cliente }}</td>
                    <td>{{ contrato.fecha_contrato }}</td>
                    <td>{{ formatear(contrato.precio_total) }}</td>
                    <td class="tenue">{{ resumenDeLineas(contrato) }}</td>
                    <td>
                        <span class="estado" :class="`estado-${contrato.estado}`">
                            {{ contrato.estado_etiqueta }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <!--
            El desglose del contrato abierto.
            Las ocho cifras están separadas a propósito: el estudio hace tres
            preguntas distintas y un total único no contesta ninguna. Ver
            `App\Services\Cobranza\DesgloseDeSaldo`.
        -->
        <section v-if="detalle" class="tarjeta" style="margin-top: 24px">
            <h3>Contrato {{ detalle.codigo }} — {{ detalle.cliente }}</h3>

            <p v-if="detalle.dias_promesa_entrega === null" class="aviso">
                Este contrato se firmó sin plazo de entrega declarado. La fecha prometida de
                entrega sale de la configuración de la instalación, y en este caso no hay ninguna.
                Ver docs/06.
            </p>

            <dl v-if="detalle.desglose" class="desglose">
                <div class="cifra">
                    <dt>Prometido</dt>
                    <dd>{{ formatear(detalle.desglose.precio_contrato) }}</dd>
                </div>
                <div class="cifra">
                    <dt>Entregado</dt>
                    <dd>{{ formatear(detalle.desglose.total_entregado) }}</dd>
                </div>
                <div class="cifra">
                    <dt>Falta entregar</dt>
                    <dd>{{ formatear(detalle.desglose.valor_por_entregar) }}</dd>
                </div>
                <div class="cifra">
                    <dt>Abonado</dt>
                    <dd>{{ formatear(detalle.desglose.total_abonado) }}</dd>
                </div>
                <div class="cifra" :class="{ 'cifra-alerta': detalle.desglose.saldo_pendiente > 0 }">
                    <dt>Saldo pendiente</dt>
                    <dd>{{ formatear(detalle.desglose.saldo_pendiente) }}</dd>
                </div>
                <div v-if="detalle.desglose.saldo_a_favor > 0" class="cifra">
                    <dt>Pagado de más</dt>
                    <dd>{{ formatear(detalle.desglose.saldo_a_favor) }}</dd>
                </div>
            </dl>

            <ul v-if="detalle.desglose?.observaciones.length" class="aviso">
                <li v-for="observacion in detalle.desglose.observaciones" :key="observacion">
                    {{ observacion }}
                </li>
            </ul>

            <h4 style="margin-top: 18px">Líneas del contrato</h4>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Concepto</th>
                        <th scope="col">Pactado</th>
                        <th scope="col">Entregado</th>
                        <th scope="col">Falta</th>
                        <th scope="col">Valor pendiente</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="linea in detalle.lineas" :key="linea.id">
                        <td>
                            {{ linea.concepto }}
                            <span class="tenue"> · {{ linea.tipo_etiqueta }}</span>
                        </td>
                        <td>
                            {{ linea.cantidad_pactada }}
                            <span v-if="linea.unidad_minutos" class="tenue">min</span>
                        </td>
                        <td>{{ linea.cantidad_entregada }}</td>
                        <td>{{ linea.cantidad_pendiente }}</td>
                        <td>{{ formatear(linea.valor_pendiente) }}</td>
                    </tr>
                </tbody>
            </table>

            <p class="tenue" style="margin-top: 12px">
                <NuxtLink to="/entregas">Registrar una entrega parcial</NuxtLink> o
                <NuxtLink to="/saldos">registrar un abono</NuxtLink>.
                Registrar una entrega NO cierra el contrato: eso pasa solo cuando todas las líneas
                quedan completas.
            </p>
        </section>
    </div>
</template>

<script setup lang="ts">
import type { Contrato, LineaContrato } from '~/tipos/api'

/**
 * Contratos y su desglose.
 *
 * POR QUE EL DESGLOSE NO SE CALCULA EN ESTA PANTALLA
 * -------------------------------------------------
 * Porque el saldo no es una cuenta: es un servicio con reglas, y la regla es que
 * devuelve el desglose. Si esta pantalla lo calculara por su cuenta, el mismo
 * número aparecería en tres lugares y alguno se desincronizaría. Ver
 * `App\Services\Cobranza\CalculadoraDeSaldo`.
 */

definePageMeta({
    middleware: 'auth',
})

const estado = ref('')
const cargando = ref(true)
const contratos = ref<Contrato[]>([])

const ruta = useRoute()

// Si se llega con `?contrato=...`, se abre ese contrato.
const detalle = ref<Contrato | null>(null)

async function cargar() {
    cargando.value = true

    try {
        const respuesta = await $fetch<{ contratos: Contrato[] }>('/api/contratos', {
            query: estado.value ? { estado: estado.value } : {},
        })

        contratos.value = respuesta.contratos

        const solicitado = typeof ruta.query.contrato === 'string' ? ruta.query.contrato : null

        if (solicitado !== null) {
            await abrir(solicitado)
        }
    } finally {
        cargando.value = false
    }
}

async function abrir(id: string) {
    const respuesta = await $fetch<{ contrato: Contrato }>(`/api/contratos/${id}`)

    detalle.value = respuesta.contrato
}

function resumenDeLineas(contrato: Contrato): string {
    const completas = contrato.lineas.filter((linea: LineaContrato) => linea.completa).length

    return `${completas} de ${contrato.lineas.length} completas`
}

function formatear(monto: number): string {
    return new Intl.NumberFormat('es-CL').format(monto)
}

await cargar()
</script>