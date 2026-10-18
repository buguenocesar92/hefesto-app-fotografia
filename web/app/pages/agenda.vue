<template>
    <div>
        <h2>Agenda de sesiones</h2>

        <div class="tarjeta">
            <div class="fila">
                <div class="fila">
                    <div class="campo">
                        <label for="desde">Desde</label>
                        <input id="desde" v-model="desde" type="date" @change="cargar" />
                    </div>
                    <div class="campo">
                        <label for="hasta">Hasta</label>
                        <input id="hasta" v-model="hasta" type="date" @change="cargar" />
                    </div>
                </div>
                <button type="button" class="boton" @click="cargar">Ver</button>
            </div>

            <!--
                El plazo de entrega se declara ACÁ, en la pantalla. Si no está
                configurado, la pantalla lo dice en vez de mostrar una fecha que
                el sistema se inventó. Un plazo sin configurar que se ve
                configurado es peor que uno que se ve pendiente. Ver docs/06.
            -->
            <p v-if="!configuracion.plazo_configurado" class="aviso">
                <strong>No hay plazo de entrega configurado en esta instalación.</strong>
                Las sesiones se agendan y se muestran, pero el sistema no les puede calcular una
                fecha prometida de entrega. Ese plazo es una promesa del estudio, y se declara en
                la configuración de la instalación, no en el software. Ver docs/06.
            </p>
            <p v-else class="tenue">
                Fecha prometida de entrega: la fecha de la sesión más
                {{ configuracion.dias_promesa_por_omision }} día(s).
            </p>
        </div>

        <p v-if="cargando" class="cargando">Cargando la agenda...</p>

        <p v-else-if="sesiones.length === 0" class="vacio">
            No hay sesiones agendadas en ese rango de fechas.
        </p>

        <table v-else>
            <thead>
                <tr>
                    <th scope="col">Fecha prometida</th>
                    <th scope="col">Sesión</th>
                    <th scope="col">Persona</th>
                    <th scope="col">Atiende</th>
                    <th scope="col">Entrega prometida</th>
                    <th scope="col">Estado</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="sesion in sesiones" :key="sesion.id">
                    <td>{{ sesion.fecha_prometida }}</td>
                    <td>
                        <NuxtLink :to="`/seleccion?sesion=${sesion.id}`">{{ sesion.titulo }}</NuxtLink>
                        <span class="tenue"> · {{ sesion.tipo_etiqueta }}</span>
                        <!--
                            Un cumpleaños o un bautismo pueden incluir a alguien
                            menor de edad. No se bloquea nada por eso: el aviso
                            es para que quien atiende lo tenga presente. El
                            bloqueo, si lo hay, lo pone la Ley y el estudio. Ver
                            docs/07.
                        -->
                        <div v-if="sesion.puede_incluir_menores" class="tenue">
                            Puede incluir a menores: confirme que tiene el consentimiento.
                        </div>
                    </td>
                    <td>{{ sesion.cliente }}</td>
                    <td>{{ sesion.fotografo ?? 'Sin asignar' }}</td>
                    <td>
                        <template v-if="sesion.fecha_prometida_entrega">
                            {{ sesion.fecha_prometida_entrega }}
                        </template>
                        <span v-else class="tenue">sin plazo configurado</span>
                    </td>
                    <td>
                        <span class="estado" :class="`estado-${sesion.estado}`">
                            {{ sesion.estado_etiqueta }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup lang="ts">
import type { SesionAgenda } from '~/tipos/api'

/**
 * La agenda: sesiones por fecha.
 *
 * POR QUE NO SE PUEDE AGENDAR DESDE ACÁ
 * -------------------------------------
 * Porque esta pantalla se abre para MIRAR el turno, y agendar es otra cosa: pide
 * persona, contrato y tipo, y cada uno tiene una regla. Agendar vive en
 * `/sesiones`, que abre el contrato primero. Ver docs/09.
 *
 * Un listado que también crea cosas termina teniendo un formulario escondido en la
 * esquina de arriba, y cuando el formulario falla no se sabe a qué sesión se
 * refiere.
 */

definePageMeta({
    middleware: 'auth',
})

interface RespuestaAgenda {
    sesiones: SesionAgenda[]
    configuracion: {
        duracion_maxima_minutos: number
        anticipacion_maxima_dias: number
        anticipacion_minima_dias: number
        dias_promesa_por_omision: number | null
        plazo_configurado: boolean
    }
}

const hoy = new Date().toISOString().slice(0, 10)
const desde = ref(hoy)
const hasta = ref(hoy)

const cargando = ref(true)
const sesiones = ref<SesionAgenda[]>([])
const configuracion = ref<RespuestaAgenda['configuracion']>({
    duracion_maxima_minutos: 0,
    anticipacion_maxima_dias: 0,
    anticipacion_minima_dias: 0,
    dias_promesa_por_omision: null,
    plazo_configurado: false,
})

async function cargar() {
    cargando.value = true

    try {
        const respuesta = await $fetch<RespuestaAgenda>('/api/agenda', {
            query: { desde: desde.value, hasta: hasta.value },
        })

        sesiones.value = respuesta.sesiones
        configuracion.value = respuesta.configuracion
    } finally {
        cargando.value = false
    }
}

await cargar()
</script>