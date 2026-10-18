<template>
    <div>
        <h2>Selección de fotos</h2>
        <p class="tenue">
            Hasta que la persona elija no hay nada que imprimir. Por eso
            <strong>pendiente de selección</strong> es un estado de trabajo normal y no un error.
        </p>

        <div class="tarjeta">
            <label for="sesion">Sesión</label>
            <input
                id="sesion"
                v-model="idDeSesion"
                placeholder="Identificador de la sesión"
                @change="cargar"
            />
            <p class="tenue" style="margin-top: 8px">
                Abra la sesión desde
                <NuxtLink to="/sesiones">la lista de sesiones</NuxtLink> o desde
                <NuxtLink to="/agenda">la agenda</NuxtLink>.
            </p>
        </div>

        <p v-if="cargando" class="cargando">Cargando la galería...</p>
        <p v-else-if="idDeSesion === ''" class="vacio">Elija una sesión para ver su galería.</p>
        <p v-else-if="mensajeDeError" class="sin-dato">{{ mensajeDeError }}</p>

        <template v-else-if="vista">
            <div class="tarjeta">
                <div class="fila">
                    <div>
                        <h3>{{ vista.sesion.titulo }}</h3>
                        <p class="tenue">
                            {{ vista.sesion.cliente }} — entrega prometida:
                            {{ vista.sesion.fecha_prometida_entrega ?? 'sin plazo configurado' }}
                        </p>
                    </div>
                    <span class="estado" :class="`estado-${vista.sesion.estado}`">
                        {{ vista.sesion.estado_etiqueta }}
                    </span>
                </div>
            </div>

            <div v-if="vista.motivo_de_no_poder_entregar" class="aviso">
                <strong>Todavía no se puede marcar como entregada.</strong>
                {{ vista.motivo_de_no_poder_entregar }}
            </div>

            <div v-if="!vista.exceso.configurado" class="aviso">
                No hay tope configurado para las fotos que se elijan por sobre las que promete el
                paquete. El sistema no bloquea y no avisa: decidir qué pasa con las fotos de más es
                del estudio, no del software. Ver docs/06.
            </div>

            <p class="tenue">
                {{ vista.conteo.elegidas }} de {{ vista.conteo.total }} fotos marcadas.
            </p>

            <div class="galeria">
                <div
                    v-for="foto in vista.fotos"
                    :key="foto.id"
                    class="foto"
                    :class="{ 'foto-elegida': foto.elegida }"
                    role="checkbox"
                    :aria-checked="foto.elegida"
                    tabindex="0"
                    @click="alternar(foto.id)"
                    @keydown.space.prevent="alternar(foto.id)"
                >
                    <!--
                        El sistema todavía no devuelve el contenido del archivo.
                        Lo que se ve es la ficha del original: nombre, tamaño y
                        huella. Poner una imagen de relleno sería hacer creer que
                        la galería funciona cuando no funciona. Ver docs/08.
                    -->
                    <div class="foto-sin-imagen">
                        {{ foto.extension ?? 'archivo' }}<br />{{ foto.nombre_archivo }}
                    </div>
                    <p class="tenue" style="margin: 6px 0 0">
                        {{ formatearBytes(foto.bytes) }}
                        <template v-if="foto.elegida"> · elegida</template>
                    </p>
                </div>
            </div>

            <p v-if="vista.fotos.length === 0" class="vacio">
                Esta sesión todavía no tiene fotos registradas. La galería se arma cuando se
                importan los originales.
            </p>

            <div v-if="puedeRegistrar && vista.fotos.length > 0" class="tarjeta">
                <div class="fila">
                    <div class="fila">
                        <button type="button" class="boton boton-secundario" @click="guardar(false)">
                            Guardar sin confirmar
                        </button>
                        <button
                            type="button"
                            class="boton"
                            :disabled="elegidas.length === 0"
                            @click="guardar(true)"
                        >
                            Confirmar la selección
                        </button>
                    </div>

                    <button
                        type="button"
                        class="boton"
                        :disabled="!puedeEntregar || !puedeConfirmarEntrega"
                        @click="marcarEntregada"
                    >
                        Marcar como entregada
                    </button>
                </div>

                <p v-if="!puedeConfirmarEntrega" class="tenue" style="margin-top: 10px">
                    El botón de entregar está deshabilitado por una razón, y no es un error de la
                    pantalla: {{ vista.motivo_de_no_poder_entregar }}
                </p>
            </div>
        </template>

        <div v-if="mensaje" class="aviso aviso-ok" role="status">{{ mensaje }}</div>
        <div v-if="error" class="aviso aviso-error" role="alert">{{ error }}</div>
    </div>
</template>

<script setup lang="ts">
import type { VistaSeleccion } from '~/tipos/api'

/**
 * La galería y la confirmación de la selección.
 *
 * POR QUE ESTA PANTALLA EXISTE Y NO ES UN DETALLE DE LA SESIÓN
 * -----------------------------------------------------------
 * Porque es donde el estudio ESPERA. La persona abre la galería, mira y elige;
 * hasta que elige, no hay nada que imprimir y el trabajo está parado esperando una
 * decisión de otro. Eso merece su propia pantalla y su propio estado.
 *
 * POR QUÉ EL BOTÓN DE ENTREGAR ESTÁ DESHABILITADO CON SU MOTIVO ESCRITO
 * ----------------------------------------------------------------------
 * Deshabilitar un botón sin decir por qué deja a la persona apretando algo
 * muerto. Y el motivo que se muestra es el MISMO que calcula el servidor, no una
 * versión suavizada: o el texto es el que va a aparecer en el error, o la persona
 * va a pensar que el sistema anda raro.
 */

definePageMeta({
    middleware: 'auth',
})

const { puedeRegistrarSeleccion, puedeDarPorEntregada } = useSesion()
const puedeRegistrar = puedeRegistrarSeleccion
const puedeEntregar = puedeDarPorEntregada

const ruta = useRoute()

const idDeSesion = ref(typeof ruta.query.sesion === 'string' ? ruta.query.sesion : '')
const vista = ref<VistaSeleccion | null>(null)
const elegidas = ref<string[]>([])
const cargando = ref(false)
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)
const mensajeDeError = ref<string | null>(null)

// El servidor ya calculó si se puede entregar y, si no, POR QUÉ. Se usa esa
// misma respuesta en vez de volver a deducirlo acá: si esta pantalla y el
// servidor usaran el mismo criterio no coinciden, el botón se activa donde no debe.
const puedeConfirmarEntrega = computed(() => vista.value?.motivo_de_no_poder_entregar == null)

async function cargar() {
    if (idDeSesion.value === '') {
        vista.value = null

        return
    }

    cargando.value = true
    mensajeDeError.value = null
    error.value = null

    try {
        vista.value = await $fetch<VistaSeleccion>(`/api/sesiones/${idDeSesion.value}/seleccion`)
        elegidas.value = vista.value.fotos.filter((foto) => foto.elegida).map((foto) => foto.id)
    } catch {
        // Un 404 puede significar dos cosas: que la sesión no existe o que el
        // permiso la escondió. Esta pantalla NO intenta distinguirlas, y no
        // reintenta. Ver docs/03.
        mensajeDeError.value = 'No encontrado.'
        vista.value = null
    } finally {
        cargando.value = false
    }
}

function alternar(id: string) {
    elegidas.value = elegidas.value.includes(id)
        ? elegidas.value.filter((otra) => otra !== id)
        : [...elegidas.value, id]
}

async function guardar(confirmar: boolean) {
    mensaje.value = null
    error.value = null

    try {
        const respuesta = await $fetch<{ mensaje: string }>(
            `/api/sesiones/${idDeSesion.value}/seleccion`,
            { method: 'POST', body: { fotos: elegidas.value, confirmar } },
        )

        mensaje.value = respuesta.mensaje
        await cargar()
    } catch (excepcion) {
        error.value = mensajeDe(excepcion)
    }
}

async function marcarEntregada() {
    mensaje.value = null
    error.value = null

    try {
        const respuesta = await $fetch<{ mensaje: string }>(
            `/api/sesiones/${idDeSesion.value}/entregar`,
            { method: 'POST' },
        )

        mensaje.value = respuesta.mensaje
        await cargar()
    } catch (excepcion) {
        // El mensaje del servidor es el que dice por qué no se pudo entregar.
        // Escribirlo acá, en vez de uno genérico, es lo que hace que la persona
        // sepa si tiene que elegir las fotos o hablar con el estudio.
        error.value = mensajeDe(excepcion)
    }
}

function mensajeDe(excepcion: unknown): string {
    const cuerpo = (excepcion as { data?: { mensaje?: string } })?.data

    return cuerpo?.mensaje ?? 'No se pudo completar la operación.'
}

function formatearBytes(bytes: number | null): string {
    if (bytes === null) {
        return 'sin tamaño declarado'
    }

    const megabytes = bytes / 1_000_000

    return `${megabytes.toFixed(1)} MB`
}

await cargar()
</script>