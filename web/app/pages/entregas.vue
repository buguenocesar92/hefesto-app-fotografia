<template>
    <div>
        <h2>Entregas</h2>
        <p class="tenue">
            Registrar una entrega parcial no cierra el contrato. El contrato se cierra solo cuando
            todas sus líneas quedan completas, y eso lo decide el sistema mirando el conjunto, no
            quien aprieta el botón.
        </p>

        <div v-if="mensaje" class="aviso aviso-ok" role="status">{{ mensaje }}</div>
        <div v-if="error" class="aviso aviso-error" role="alert">{{ error }}</div>

        <div class="tarjeta">
            <label for="contrato">Contrato</label>
            <select id="contrato" v-model="idDeContrato" @change="cargarDetalle">
                <option value="">Elija un contrato activo</option>
                <option v-for="contrato in contratos" :key="contrato.id" :value="contrato.id">
                    {{ contrato.codigo }} — {{ contrato.cliente }}
                </option>
            </select>
        </div>

        <p v-if="cargando" class="cargando">Cargando el contrato...</p>
        <p v-else-if="detalle === null" class="vacio">
            Elija un contrato para ver sus líneas y registrar una entrega.
        </p>

        <section v-else class="tarjeta">
            <h3>{{ detalle.codigo }} — {{ detalle.cliente }}</h3>
            <p class="tenue">
                Estado: {{ detalle.estado_etiqueta }} · Prometido:
                {{ formatear(detalle.precio_total) }}
            </p>

            <table>
                <thead>
                    <tr>
                        <th scope="col">Línea</th>
                        <th scope="col">Pactado</th>
                        <th scope="col">Entregado</th>
                        <th scope="col">Falta</th>
                        <th scope="col">Registrar</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="linea in detalle.lineas" :key="linea.id">
                        <td>
                            {{ linea.concepto }}
                            <span class="tenue"> · {{ linea.tipo_etiqueta }}</span>
                            <!--
                                Una línea de impresión o de álbum depende de que
                                haya una selección confirmada. La pantalla lo
                                avisa antes de dejar escribir, y el servidor lo
                                vuelve a comprobar. Ver docs/02.
                            -->
                            <div
                                v-if="necesitaSeleccion(linea.tipo)"
                                class="tenue"
                            >
                                Esta línea no se puede dar por entregada sin una selección
                                confirmada de la sesión.
                            </div>
                        </td>
                        <td>
                            {{ linea.cantidad_pactada }}
                            <span v-if="linea.unidad_minutos" class="tenue">min</span>
                        </td>
                        <td>{{ linea.cantidad_entregada }}</td>
                        <td>
                            <span :class="{ 'estado-vencido': linea.cantidad_pendiente > 0 }">
                                {{ linea.cantidad_pendiente }}
                            </span>
                        </td>
                        <td>
                            <form class="fila" @submit.prevent="registrar(linea.id)">
                                <input
                                    v-model="cantidades[linea.id]"
                                    type="number"
                                    min="1"
                                    :max="linea.cantidad_pendiente"
                                    style="width: 90px"
                                    :aria-label="`Cantidad a entregar de ${linea.concepto}`"
                                />
                                <button
                                    type="submit"
                                    class="boton boton-secundario"
                                    :disabled="!puedeRegistrar || linea.cantidad_pendiente === 0"
                                >
                                    Registrar
                                </button>
                            </form>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="tenue" style="margin-top: 12px">
                La cantidad que se registra es la que se entrega AHORA, no el total. Si se escribiera
                el total, la segunda entrega se perdería.
            </p>
        </section>
    </div>
</template>

<script setup lang="ts">
import type { Contrato, LineaContrato } from '~/tipos/api'

/**
 * Registro de entregas parciales.
 *
 * POR QUE EL CAMPO ES "AHORA" Y NO "TOTAL"
 * ----------------------------------------
 * Es la diferencia entre una entrega parcial y un sobrescrito. Si el campo fuera el
 * total, el primer registro dejaría la línea en el número que la pantalla ya
 * traía, y la segunda entrega se perdería sin que nadie lo notara. Ver
 * `RegistrarEntregaRequest`.
 *
 * POR QUE EL BOTÓN NO DESAPARECE CUANDO LA LÍNEA ESTÁ COMPLETA
 * ----------------------------------------------------------
 * Está deshabilitado, no escondido. Es la diferencia entre "esto ya no se puede
 * hacer" y "esto no existe", y una línea completa sigue necesitando verse para que
 * quede claro que está completa y por qué.
 */

definePageMeta({
    middleware: 'auth',
})

const { puedeGestionarContratos } = useSesion()
const puedeRegistrar = puedeGestionarContratos

const ruta = useRoute()

const contratos = ref<Contrato[]>([])
const detalle = ref<Contrato | null>(null)
const cantidades = reactive<Record<string, number | null>>({})
const idDeContrato = ref(typeof ruta.query.contrato === 'string' ? ruta.query.contrato : '')
const cargando = ref(false)
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)

/** Impresión y álbum no se entregan sin selección confirmada. */
function necesitaSeleccion(tipo: string): boolean {
    return tipo === 'impresion' || tipo === 'album'
}

const { data } = await useFetch<{ contratos: Contrato[] }>('/api/contratos', {
    query: { estado: 'activo' },
})

watchEffect(() => {
    if (data.value?.contratos) {
        contratos.value = data.value.contratos
    }
})

async function cargarDetalle() {
    if (idDeContrato.value === '') {
        detalle.value = null

        return
    }

    cargando.value = true
    mensaje.value = null
    error.value = null

    try {
        const respuesta = await $fetch<{ contrato: Contrato }>(`/api/contratos/${idDeContrato.value}`)

        detalle.value = respuesta.contrato

        for (const linea of respuesta.contrato.lineas) {
            cantidades[linea.id] = linea.cantidad_pendiente
        }
    } catch (excepcion) {
        error.value = mensajeDe(excepcion)
        detalle.value = null
    } finally {
        cargando.value = false
    }
}

async function registrar(lineaId: string) {
    const cantidad = cantidades[lineaId]

    if (! cantidad || cantidad <= 0) {
        error.value = 'La cantidad a entregar tiene que ser mayor que cero.'

        return
    }

    mensaje.value = null
    error.value = null

    try {
        const respuesta = await $fetch<{ mensaje: string }>(
            `/api/contratos/${idDeContrato.value}/entregas`,
            { method: 'POST', body: { linea_id: lineaId, cantidad } },
        )

        mensaje.value = respuesta.mensaje
        await cargarDetalle()
    } catch (excepcion) {
        error.value = mensajeDe(excepcion)
    }
}

function mensajeDe(excepcion: unknown): string {
    const cuerpo = (excepcion as { data?: { mensaje?: string } })?.data

    return cuerpo?.mensaje ?? 'No se pudo registrar la entrega.'
}

function formatear(monto: number): string {
    return new Intl.NumberFormat('es-CL').format(monto)
}

if (idDeContrato.value !== '') {
    await cargarDetalle()
}
</script>