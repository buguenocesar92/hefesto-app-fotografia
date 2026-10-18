<template>
    <div>
        <h2>Sesiones</h2>
        <p class="tenue">
            Las tomas con fecha prometida. Se agendan con la fecha que se le dijo a la persona, y
            esa fecha no se sobrescribe cuando la sesión se realiza: queda en su columna para poder
            responder por ella después.
        </p>

        <div v-if="mensaje" class="aviso aviso-ok" role="status">{{ mensaje }}</div>
        <div v-if="error" class="aviso aviso-error" role="alert">{{ error }}</div>

        <section v-if="puedeAgendar" class="tarjeta">
            <h3>Agendar una sesión</h3>

            <form class="columnas" @submit.prevent="agendar">
                <div class="campo">
                    <label for="cliente">Persona</label>
                    <input id="cliente" v-model="formulario.cliente_id" required />
                </div>
                <div class="campo">
                    <label for="titulo">Título</label>
                    <input id="titulo" v-model="formulario.titulo" required maxlength="200" />
                </div>
                <div class="campo">
                    <label for="tipo">Tipo</label>
                    <select id="tipo" v-model="formulario.tipo" required>
                        <option v-for="tipo in tipos" :key="tipo.valor" :value="tipo.valor">
                            {{ tipo.etiqueta }}
                        </option>
                    </select>
                    <!--
                        Un cumpleaños, un matrimonio o un bautismo pueden incluir a
                        alguien menor de edad. El aviso es para que quien atiende lo
                        tenga presente; el bloqueo, si lo hay, lo pone la Ley y el
                        estudio, no esta pantalla. Ver docs/07.
                    -->
                    <p v-if="tipoActual?.puede_incluir_menores" class="tenue">
                        Este tipo de sesión puede incluir a menores. Confirme que tiene el
                        consentimiento de quien corresponde.
                    </p>
                </div>
                <div class="campo">
                    <label for="fecha">Fecha prometida</label>
                    <input id="fecha" v-model="formulario.fecha_prometida" type="date" required />
                </div>
                <div class="campo">
                    <label for="lugar">Lugar</label>
                    <input id="lugar" v-model="formulario.lugar" maxlength="500" />
                </div>
                <div class="campo">
                    <label for="duracion">Duración en minutos</label>
                    <input
                        id="duracion"
                        v-model="formulario.duracion_minutos"
                        type="number"
                        min="15"
                        :max="limites.duracion_maxima_minutos"
                    />
                </div>

                <div>
                    <button type="submit" class="boton" :disabled="enviando">
                        {{ enviando ? 'Agendando...' : 'Agendar' }}
                    </button>
                </div>
            </form>

            <!--
                El plazo de entrega se DECLARA en la pantalla, y solo si la
                instalación lo tiene configurado. Con el plazo en `null` la
                fecha prometida de entrega queda en `null` y se muestra como
                pendiente. Un plazo que el sistema se inventa es una promesa que
                el estudio nunca hizo. Ver docs/06.
            -->
            <p v-if="!limites.plazo_configurado" class="aviso">
                Esta instalación no tiene plazo de entrega configurado. La sesión se puede agendar,
                pero el sistema no le va a calcular una fecha prometida de entrega.
            </p>
        </section>

        <p v-if="cargando" class="cargando">Cargando las sesiones...</p>
        <p v-else-if="sesiones.length === 0" class="vacio">No hay sesiones registradas.</p>

        <table v-else>
            <thead>
                <tr>
                    <th scope="col">Fecha prometida</th>
                    <th scope="col">Sesión</th>
                    <th scope="col">Persona</th>
                    <th scope="col">Realizada</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="sesion in sesiones" :key="sesion.id">
                    <td>{{ sesion.fecha_prometida }}</td>
                    <td>
                        {{ sesion.titulo }}
                        <span class="tenue"> · {{ sesion.tipo_etiqueta }}</span>
                    </td>
                    <td>{{ sesion.cliente }}</td>
                    <td>{{ sesion.fecha_realizada ?? '—' }}</td>
                    <td>
                        <span class="estado" :class="`estado-${sesion.estado}`">
                            {{ sesion.estado_etiqueta }}
                        </span>
                    </td>
                    <td>
                        <!--
                            "Pendiente de selección" NO muestra error: es un
                            estado de trabajo normal. Lo que muestra es un
                            enlace a la galería, que es lo que hay que hacer.
                        -->
                        <NuxtLink :to="`/seleccion?sesion=${sesion.id}`">Ver selección</NuxtLink>
                        <button
                            v-if="sesion.estado === 'agendada'"
                            type="button"
                            class="boton boton-secundario"
                            style="margin-left: 8px"
                            @click="marcarRealizada(sesion.id)"
                        >
                            Marcar realizada
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup lang="ts">
import type { SesionAgenda } from '~/tipos/api'

/**
 * Sesiones: agendar y ver el estado de cada una.
 *
 * POR QUE AGENDAR VIVE ACÁ Y NO EN LA AGENDA
 * -----------------------------------------
 * Porque agendar pide tres datos que la agenda no tiene: la persona, el contrato
 * al que pertenece y el tipo. Meter un formulario en la pantalla de la agenda
 * termina con un formulario escondido en una esquina, y cuando falla no se sabe a
 * qué sesión se refiere.
 *
 * POR QUE EL PLAZO LO ESCRIBE LA PANTALLA Y NO EL CÓDIGO
 * -----------------------------------------------------
 * El plazo entre la sesión y la entrega es una promesa del estudio. Sale de
 * `config('fotografia.entrega.dias_promesa_por_omision')`, y si esa instalación
 * no lo tiene, no se inventa ninguno. Ver docs/06.
 */

definePageMeta({
    middleware: 'auth',
})

interface TipoSesion {
    valor: string
    etiqueta: string
    puede_incluir_menores: boolean
    fecha_critica: boolean
}

const { puedeGestionarContratos } = useSesion()
const puedeAgendar = puedeGestionarContratos

const cargando = ref(true)
const enviando = ref(false)
const sesiones = ref<SesionAgenda[]>([])
const tipos = ref<TipoSesion[]>([])
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)

const limites = ref<{
    duracion_maxima_minutos: number
    dias_promesa_por_omision: number | null
    plazo_configurado: boolean
}>({
    duracion_maxima_minutos: 600,
    dias_promesa_por_omision: null,
    plazo_configurado: false,
})

const formulario = reactive({
    cliente_id: '',
    titulo: '',
    tipo: 'cumpleanos',
    fecha_prometida: '',
    lugar: '',
    duracion_minutos: null as number | null,
})

const tipoActual = computed(() => tipos.value.find((tipo) => tipo.valor === formulario.tipo) ?? null)

async function cargar() {
    cargando.value = true

    try {
        const [agenda, listaTipos] = await Promise.all([
            $fetch<{ sesiones: SesionAgenda[]; configuracion: typeof limites.value }>('/api/agenda'),
            $fetch<{ tipos: TipoSesion[] }>('/api/agenda/tipos'),
        ])

        sesiones.value = agenda.sesiones
        limites.value = agenda.configuracion
        tipos.value = listaTipos.tipos
    } catch {
        error.value = 'No se pudieron cargar las sesiones.'
    } finally {
        cargando.value = false
    }
}

async function agendar() {
    enviando.value = true
    mensaje.value = null
    error.value = null

    try {
        const respuesta = await $fetch<{ mensaje: string }>('/api/sesiones', {
            method: 'POST',
            body: {
                cliente_id: formulario.cliente_id,
                titulo: formulario.titulo,
                tipo: formulario.tipo,
                fecha_prometida: formulario.fecha_prometida,
                lugar: formulario.lugar === '' ? null : formulario.lugar,
                duracion_minutos: formulario.duracion_minutos,
            },
        })

        mensaje.value = respuesta.mensaje
        formulario.titulo = ''
        formulario.lugar = ''
        formulario.fecha_prometida = ''

        await cargar()
    } catch (excepcion) {
        error.value = mensajeDe(excepcion)
    } finally {
        enviando.value = false
    }
}

async function marcarRealizada(id: string) {
    mensaje.value = null
    error.value = null

    try {
        const respuesta = await $fetch<{ mensaje: string }>(`/api/sesiones/${id}/realizada`, {
            method: 'POST',
            body: { fecha_realizada: new Date().toISOString().slice(0, 10) },
        })

        mensaje.value = respuesta.mensaje
        await cargar()
    } catch (excepcion) {
        error.value = mensajeDe(excepcion)
    }
}

/**
 * El mensaje del servidor, si lo hay.
 *
 * La API devuelve 404 y 422 a propósito, y el 422 trae el motivo del dominio
 * ("no hay selección confirmada", "esa línea promete más de lo entregado"). Mostrar
 * ese texto es lo único que sirve: un mensaje genérico dejaría a la persona sin
 * saber qué hacer. Ver docs/03.
 */
function mensajeDe(excepcion: unknown): string {
    const cuerpo = (excepcion as { data?: { mensaje?: string } })?.data

    return cuerpo?.mensaje ?? 'No se pudo completar la operación.'
}

await cargar()
</script>