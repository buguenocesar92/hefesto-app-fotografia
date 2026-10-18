<template>
    <div>
        <h2>Paquetes y medios</h2>
        <p class="tenue">
            Dos catálogos que no tienen nada que ver entre sí pero que se necesitan juntos: lo que el
            estudio ofrece, y dónde guarda lo que le entregaría de por vida.
        </p>

        <div v-if="mensaje" class="aviso aviso-ok" role="status">{{ mensaje }}</div>
        <div v-if="error" class="aviso aviso-error" role="alert">{{ error }}</div>

        <!--
            Los paquetes.
            `precio_referencia` es solo el punto de partida: al contratar se copian
            las líneas con su precio, y ahí queda congelado lo que firmó la persona.
            Cambiar este número no le hace nada a nadie de antes. Ver docs/06.
        -->
        <section class="tarjeta">
            <h3>Paquetes del estudio</h3>

            <p v-if="!puedeAdministrarPaquetes" class="tenue">
                La lista la ve todo el estudio. Cambiarla es de administración.
            </p>

            <p v-if="paquetes.length === 0" class="vacio">
                Todavía no hay paquetes definidos. Sin un paquete no se puede abrir un contrato.
            </p>

            <table v-else>
                <thead>
                    <tr>
                        <th scope="col">Código</th>
                        <th scope="col">Nombre</th>
                        <th scope="col">Descripción</th>
                        <th scope="col">Precio de referencia</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="paquete in paquetes" :key="paquete.id">
                        <td>{{ paquete.codigo }}</td>
                        <td>{{ paquete.nombre }}</td>
                        <td class="tenue">{{ paquete.descripcion ?? '—' }}</td>
                        <td>{{ formatear(paquete.precio_referencia) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!--
            Los medios con originales.
            Un estudio entrega las fotos de por vida. Si el medio se pierde, se
            pierden las sesiones de tres años, y esa es la pérdida más grande del
            rubro. Por esto hay una tabla con estado, y no un campo de texto con
            la ruta. Ver docs/08.
        -->
        <section class="tarjeta">
            <h3>Dónde están los originales</h3>

            <div v-if="!informe.plazo_configurado" class="aviso">
                <strong>No hay plazo de verificación configurado.</strong>
                El sistema no avisa por antigüedad de los medios a propósito: no se pudo verificar
                un número que sirva para el rubro, y un aviso falso entrena al equipo a ignorar el
                sistema. La lista de abajo hay que mirarla a mano.
            </div>

            <p v-if="conservaciones.length === 0" class="vacio">
                No hay medios registrados. Un estudio sin saber dónde guarda las fotos es un estudio
                que pierde las primeras.
            </p>

            <table v-else>
                <thead>
                    <tr>
                        <th scope="col">Medio</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">Ubicación</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Última verificación</th>
                        <th scope="col">Sesiones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="medio in conservaciones" :key="medio.id">
                        <td>{{ medio.nombre }}</td>
                        <td class="tenue">{{ medio.tipo_medio }}</td>
                        <td class="tenue">{{ medio.ubicacion }}</td>
                        <td>
                            <span
                                class="estado"
                                :class="medio.operativa ? 'estado-entregada' : 'estado-vencido'"
                            >
                                {{ medio.estado_etiqueta }}
                            </span>
                        </td>
                        <td class="tenue">
                            <template v-if="medio.dias_sin_verificar === null">
                                nunca verificado
                            </template>
                            <template v-else>hace {{ medio.dias_sin_verificar }} día(s)</template>
                        </td>
                        <td>{{ medio.sesiones_asignadas }}</td>
                    </tr>
                </tbody>
            </table>

            <div v-if="informe.sesiones_en_riesgo.length > 0" class="aviso aviso-error">
                <strong>
                    Hay {{ informe.sesiones_en_riesgo.length }} sesión(es) ya entregada(s) con los
                    originales en un medio que no está operativo.
                </strong>
                Son promesas que el estudio ya no puede cumplir. Ver docs/08.
                <ul>
                    <li v-for="sesion in informe.sesiones_en_riesgo" :key="sesion.id">
                        {{ sesion.titulo }} — {{ sesion.conservacion }}
                        [{{ sesion.estado_conservacion }}]
                    </li>
                </ul>
            </div>

            <form v-if="puedeAdministrar" class="columnas" @submit.prevent="guardarMedio">
                <div class="campo">
                    <label for="medio-nombre">Nombre del medio</label>
                    <input id="medio-nombre" v-model="medio.nombre" required maxlength="160" />
                </div>
                <div class="campo">
                    <label for="medio-tipo">Tipo</label>
                    <select id="medio-tipo" v-model="medio.tipo_medio" required>
                        <option v-for="tipo in tiposDeMedio" :key="tipo" :value="tipo">
                            {{ tipo }}
                        </option>
                    </select>
                    <p class="tenue">
                        La lista es cerrada a propósito. Un texto libre acá permitiría escribir "en
                        el escritorio de la recepción", que es una promesa de custodia que nadie
                        puede cumplir. Ver docs/08.
                    </p>
                </div>
                <div class="campo">
                    <label for="medio-ubicacion">Ubicación</label>
                    <input id="medio-ubicacion" v-model="medio.ubicacion" required maxlength="500" />
                </div>
                <div class="campo">
                    <label for="medio-estado">Estado</label>
                    <select id="medio-estado" v-model="medio.estado" required>
                        <option value="activa">Activa</option>
                        <option value="desconectada">Desconectada</option>
                        <option value="danada">Con daño</option>
                        <option value="destruida">Destruida</option>
                    </select>
                </div>

                <div>
                    <button type="submit" class="boton" :disabled="enviando">
                        {{ enviando ? 'Guardando...' : 'Registrar medio' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>

<script setup lang="ts">
import type { Conservacion, InformeConservacion, Paquete } from '~/tipos/api'

/**
 * Paquetes y medios con originales.
 *
 * POR QUÉ ESTAS DOS COSAS VIVEN EN LA MISMA PANTALLA
 * --------------------------------------------------
 * Porque son las dos preguntas que se responden al mismo instante, al abrir un
 * estudio: "¿qué ofrezco?" y "¿dónde queda lo que entrego?". Separarlas las obliga a
 * saltar entre pantallas para responder una pregunta de custodia.
 *
 * POR QUÉ LOS PRECIOS NO ESTÁN ACÁ
 * --------------------------------
 * Porque los declara el estudio, uno por uno, y este catálogo solo muestra lo que
 * ya está declarado. No hay un precio por omisión en ninguna parte del sistema: un
 * precio inventado es una promesa que el estudio nunca hizo. Ver docs/05.
 */

definePageMeta({
    middleware: 'auth',
})

const { puedeAdministrarPaquetes, puedeAdministrar } = useSesion()

const paquetes = ref<Paquete[]>([])
const conservaciones = ref<Conservacion[]>([])
const tiposDeMedio = ref<string[]>([])
const informe = ref<InformeConservacion>({
    dias_max_sin_verificar: null,
    plazo_configurado: false,
    pendientes: [],
    sesiones_en_riesgo: [],
})
const enviando = ref(false)
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)

const medio = reactive({
    nombre: '',
    tipo_medio: 'disco_local',
    ubicacion: '',
    estado: 'activa',
})

async function guardarMedio() {
    enviando.value = true
    mensaje.value = null
    error.value = null

    try {
        await $fetch('/api/conservaciones', { method: 'POST', body: medio })

        mensaje.value = 'Medio registrado.'

        medio.nombre = ''
        medio.ubicacion = ''

        await cargar()
    } catch (excepcion) {
        const cuerpo = (excepcion as { data?: { mensaje?: string } })?.data

        error.value = cuerpo?.mensaje ?? 'No se pudo registrar el medio.'
    } finally {
        enviando.value = false
    }
}

async function cargar() {
    try {
        const [listaPaquetes, medios] = await Promise.all([
            $fetch<{ paquetes: Paquete[] }>('/api/administracion/paquetes'),
            $fetch<{
                conservaciones: Conservacion[]
                tipos_de_medio: string[]
                informe: InformeConservacion
            }>('/api/conservaciones'),
        ])

        paquetes.value = listaPaquetes.paquetes
        conservaciones.value = medios.conservaciones
        tiposDeMedio.value = medios.tipos_de_medio
        informe.value = medios.informe
    } catch (excepcion) {
        const cuerpo = (excepcion as { data?: { mensaje?: string } })?.data

        // Un 404 acá puede ser que la persona no administre el estudio, no que
        // falten datos. No se reintenta ni se deduce nada.
        error.value = cuerpo?.mensaje ?? 'No se pudieron cargar los catálogos.'
    }
}

function formatear(monto: number): string {
    return new Intl.NumberFormat('es-CL').format(monto)
}

await cargar()
</script>