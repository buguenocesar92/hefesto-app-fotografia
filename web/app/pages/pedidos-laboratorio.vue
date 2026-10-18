<template>
    <div>
        <h2>Pedidos al laboratorio</h2>
        <p class="tenue">
            El estudio no vende papel: encarga la impresión. Eso significa que hay una fecha
            prometida al cliente y otra pactada con el laboratorio, y son dos promesas distintas.
        </p>

        <div v-if="mensaje" class="aviso aviso-ok" role="status">{{ mensaje }}</div>
        <div v-if="error" class="aviso aviso-error" role="alert">{{ error }}</div>

        <div v-if="!plazoLaboratorioConfigurado" class="aviso">
            <strong>No hay plazo de laboratorio configurado en esta instalación.</strong>
            Los pedidos se pueden abrir igual, pero el sistema no les va a calcular una fecha
            prometida. Ese plazo se pacta uno por uno con cada laboratorio, y no se pudo verificar un
            número que sirva para todos. Ver docs/06.
        </div>

        <p v-if="cargando" class="cargando">Cargando los pedidos...</p>
        <p v-else-if="pedidos.length === 0" class="vacio">
            No hay pedidos al laboratorio.
        </p>

        <table v-else>
            <thead>
                <tr>
                    <th scope="col">Código</th>
                    <th scope="col">Laboratorio</th>
                    <th scope="col">Enviado</th>
                    <th scope="col">Prometido al cliente</th>
                    <th scope="col">Recibido</th>
                    <th scope="col">Estado</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="pedido in pedidos" :key="pedido.id">
                    <td>{{ pedido.codigo }}</td>
                    <td>{{ pedido.laboratorio }}</td>
                    <td>{{ pedido.fecha_envio ?? '—' }}</td>
                    <td>
                        <template v-if="pedido.fecha_prometida_recepcion">
                            {{ pedido.fecha_prometida_recepcion }}
                            <span v-if="pedido.vencido" class="estado estado-vencido">vencido</span>
                        </template>
                        <span v-else class="tenue">sin plazo configurado</span>
                    </td>
                    <td>
                        {{ pedido.cantidad_recibida }} de {{ pedido.cantidad_pedida }}
                        <!--
                            Los faltantes se muestran SIEMPRE como una cantidad
                            que falta, y el pedido queda en
                            `recibido_con_fallos`. Marcarlo como recibido
                            completo sería mentir sobre lo que se le entregó a
                            la persona. Ver docs/02.
                        -->
                        <span v-if="pedido.tiene_faltantes" class="estado estado-vencido">
                            con faltantes
                        </span>
                    </td>
                    <td>
                        <span class="estado" :class="pedido.vencido ? 'estado-vencido' : 'estado-agenda'">
                            {{ pedido.estado_etiqueta }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <section class="tarjeta">
            <h3>Abrir un pedido</h3>

            <form class="columnas" @submit.prevent="abrir">
                <div class="campo">
                    <label for="pedido-laboratorio">Laboratorio</label>
                    <select id="pedido-laboratorio" v-model="formulario.laboratorio_id" required>
                        <option value="">Elija un laboratorio</option>
                        <option v-for="lab in laboratorios" :key="lab.id" :value="lab.id">
                            {{ lab.nombre }}
                        </option>
                    </select>
                </div>
                <div class="campo">
                    <label for="pedido-codigo">Código</label>
                    <input id="pedido-codigo" v-model="formulario.codigo" required maxlength="32" />
                </div>
                <div class="campo">
                    <label for="pedido-sesion">Identificador de la sesión</label>
                    <input id="pedido-sesion" v-model="formulario.sesion_id" maxlength="36" />
                    <p class="tenue">
                        Sin sesión no se puede vincular: se puede encargar algo que no pertenece a
                        ninguna sesión, pero entonces el estudio tiene que acordarlo con el
                        laboratorio por otro lado.
                    </p>
                </div>
                <div class="campo">
                    <label for="pedido-prometido">Fecha prometida al cliente</label>
                    <input id="pedido-prometido" v-model="formulario.fecha_prometida_recepcion" type="date" />
                </div>
                <div class="campo">
                    <label for="pedido-dias">Días pactados con el laboratorio</label>
                    <input id="pedido-dias" v-model.number="formulario.dias_plazo_laboratorio" type="number" min="0" />
                </div>
                <div class="campo">
                    <label for="pedido-formato">Formato</label>
                    <!--
                        El formato es TEXTO y sale de la configuración del
                        laboratorio, no de una lista del software. Cada
                        laboratorio ofrece formatos distintos y los precios
                        cambian por temporada; una lista escrita acá sería falsa
                        en cuatro de cada cinco. Ver docs/05.
                    -->
                    <input
                        id="pedido-formato"
                        v-model="formulario.formato"
                        list="formatos-del-laboratorio"
                        required
                        maxlength="60"
                    />
                    <datalist id="formatos-del-laboratorio">
                        <option v-for="formato in formatosConfigurados" :key="formato" :value="formato" />
                    </datalist>
                    <p v-if="formatosConfigurados.length === 0" class="tenue">
                        Esta instalación no tiene formatos declarados: el sistema no impone ninguno y
                        escribe el que el laboratorio acepte.
                    </p>
                </div>
                <div class="campo">
                    <label for="pedido-cantidad">Cantidad</label>
                    <input id="pedido-cantidad" v-model.number="formulario.cantidad" type="number" min="1" required />
                </div>
                <div class="campo">
                    <label for="pedido-precio">Precio unitario</label>
                    <input id="pedido-precio" v-model.number="formulario.precio_unitario" type="number" min="0" required />
                </div>

                <div>
                    <button type="submit" class="boton" :disabled="enviando">
                        {{ enviando ? 'Abriendo...' : 'Abrir pedido' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>

<script setup lang="ts">
import type { Laboratorio, PedidoLaboratorio } from '~/tipos/api'

/**
 * Pedidos al laboratorio.
 *
 * POR QUÉ LAS DOS FECHAS ESTÁN EN COLUMNAS DISTINTAS
 * --------------------------------------------------
 * `fecha_prometida_recepcion` es lo que el estudio le dijo al CLIENTE.
 * `dias_plazo_laboratorio` es lo que le pidió al LABORATORIO. Confundirlas es como
 * un estudio termina diciéndole a la gente que el laboratorio es culpable de una
 * fecha que el estudio mismo se dio.
 *
 * POR QUÉ EL FORMATO ES UN CAMPO DE TEXTO
 * ---------------------------------------
 * Porque cada laboratorio ofrece formatos distintos y los precios cambian por
 * temporada. Si esta pantalla trajera una lista, sería una lista que es falsa en
 * cuatro de cada cinco laboratorios. Los formatos que la instalación conoce
 * aparecen como sugerencia, y si no hay ninguno, se escribe el que acepte el
 * laboratorio. Ver docs/05.
 */

definePageMeta({
    middleware: 'auth',
})

const cargando = ref(true)
const enviando = ref(false)
const pedidos = ref<PedidoLaboratorio[]>([])
const laboratorios = ref<Laboratorio[]>([])
const formatosConfigurados = ref<string[]>([])
const plazoLaboratorioConfigurado = ref(true)
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)

const formulario = reactive({
    laboratorio_id: '',
    sesion_id: '',
    codigo: '',
    fecha_prometida_recepcion: '',
    dias_plazo_laboratorio: null as number | null,
    formato: '',
    cantidad: 1,
    precio_unitario: 0,
})

async function abrir() {
    enviando.value = true
    mensaje.value = null
    error.value = null

    try {
        await $fetch('/api/laboratorio', {
            method: 'POST',
            body: {
                laboratorio_id: formulario.laboratorio_id,
                sesion_id: formulario.sesion_id === '' ? null : formulario.sesion_id,
                codigo: formulario.codigo,
                fecha_prometida_recepcion:
                    formulario.fecha_prometida_recepcion === ''
                        ? null
                        : formulario.fecha_prometida_recepcion,
                dias_plazo_laboratorio: formulario.dias_plazo_laboratorio,
                estado: 'borrador',
                items: [
                    {
                        tipo: 'impresion',
                        formato: formulario.formato,
                        cantidad_pedida: formulario.cantidad,
                        precio_unitario: formulario.precio_unitario,
                    },
                ],
            },
        })

        mensaje.value = 'Pedido abierto en borrador. Envíelo al laboratorio cuando esté listo.'

        formulario.codigo = ''
        formulario.formato = ''

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
        const [lista, directorio] = await Promise.all([
            $fetch<{ pedidos: PedidoLaboratorio[]; formatos: string[] | null }>('/api/laboratorio'),
            $fetch<{
                laboratorios: Laboratorio[]
                dias_promesa_laboratorio: number | null
                plazo_configurado: boolean
            }>('/api/laboratorio/directorios'),
        ])

        pedidos.value = lista.pedidos
        formatosConfigurados.value = lista.formatos ?? []
        laboratorios.value = directorio.laboratorios
        plazoLaboratorioConfigurado.value = directorio.plazo_configurado
    } finally {
        cargando.value = false
    }
}

function mensajeDe(excepcion: unknown): string {
    const cuerpo = (excepcion as { data?: { mensaje?: string } })?.data

    return cuerpo?.mensaje ?? 'No se pudo abrir el pedido.'
}

await cargar()
</script>