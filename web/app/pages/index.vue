<template>
    <div>
        <h2>Panel del estudio</h2>

        <p v-if="cargando" class="cargando">Cargando el panel...</p>

        <div v-else-if="panel" class="columnas">
            <!--
                Las tres preguntas con las que se abre un estudio todas las
                mañanas. Cada una tiene su propio bloque y ninguno comparte
                tarjeta con otro: mezclarlas haría que la más urgente quedara
                abajo del todo.
            -->
            <section class="tarjeta">
                <h3>Esperando que la persona elija</h3>
                <p v-if="panel.pendientes_de_seleccion.length === 0" class="vacio">
                    No hay sesiones esperando selección.
                </p>
                <template v-else>
                    <p class="tenue">
                        No es un error: es el estado normal de una sesión que se tomó y en la que
                        la persona todavía no eligió.
                    </p>
                    <ul>
                        <li v-for="sesion in panel.pendientes_de_seleccion" :key="sesion.id">
                            <NuxtLink :to="`/seleccion?sesion=${sesion.id}`">
                                {{ sesion.titulo }}
                            </NuxtLink>
                            <span class="tenue">
                                — entrega prometida:
                                {{ sesion.fecha_prometida_entrega ?? 'sin plazo configurado' }}
                            </span>
                            <span
                                v-if="sesion.atrasada"
                                class="estado estado-vencido"
                                style="margin-left: 8px"
                            >
                                La fecha ya pasó
                            </span>
                        </li>
                    </ul>
                </template>
            </section>

            <section class="tarjeta">
                <h3>Encargos al laboratorio</h3>
                <p v-if="panel.pedidos_laboratorio_vencidos.length === 0" class="vacio">
                    No hay encargos con la fecha prometida ya pasada.
                </p>
                <ul v-else>
                    <li v-for="pedido in panel.pedidos_laboratorio_vencidos" :key="pedido.id">
                        {{ pedido.codigo }} — {{ pedido.laboratorio }}
                        <span class="tenue">
                            (prometido: {{ pedido.fecha_prometida_recepcion }})
                        </span>
                    </li>
                </ul>
            </section>

            <section class="tarjeta">
                <h3>Caja</h3>
                <dl class="desglose">
                    <div class="cifra">
                        <dt>Contratos abiertos</dt>
                        <dd>{{ panel.caja.contratos_abiertos }}</dd>
                    </div>
                    <div class="cifra" :class="{ 'cifra-alerta': panel.caja.total_por_cobrar > 0 }">
                        <dt>Por cobrar</dt>
                        <dd>{{ formatear(panel.caja.total_por_cobrar) }}</dd>
                    </div>
                </dl>
                <NuxtLink to="/saldos" class="boton boton-secundario">Ver el desglose</NuxtLink>
            </section>

            <!--
                La parte que no es una lista: los originales.
                Un estudio entrega las fotos de por vida, así que saber dónde están
                y si el medio sigue vivo es tan importante como saber qué sesiones
                hay mañana.
            -->
            <section class="tarjeta">
                <h3>Dónde están los originales</h3>

                <div v-if="!panel.conservacion.plazo_configurado" class="aviso">
                    <strong>No hay plazo de verificación configurado.</strong>
                    El sistema no avisa por antigüedad de los medios a propósito: no se pudo
                    verificar un número que sirva para el rubro, y un aviso falso entrena al
                    equipo a ignorar el sistema. La lista de abajo hay que mirarla a mano.
                </div>

                <p v-if="panel.conservacion.pendientes.length === 0" class="vacio">
                    Todos los medios están activos y verificados.
                </p>
                <ul v-else>
                    <li v-for="medio in panel.conservacion.pendientes" :key="medio.id">
                        {{ medio.nombre }}
                        <span class="estado estado-pendiente_seleccion">
                            {{ medio.estado_etiqueta }}
                        </span>
                        <span class="tenue">
                            —
                            {{
                                medio.dias_sin_verificar === null
                                    ? 'nunca verificado'
                                    : `hace ${medio.dias_sin_verificar} día(s)`
                            }}
                        </span>
                    </li>
                </ul>

                <div
                    v-if="panel.conservacion.sesiones_en_riesgo.length > 0"
                    class="aviso aviso-error"
                >
                    <strong>
                        Hay {{ panel.conservacion.sesiones_en_riesgo.length }} sesión(es) ya
                        entregada(s) con los originales en un medio que no está operativo.
                    </strong>
                    Son promesas que el estudio ya no puede cumplir.
                    <ul>
                        <li v-for="sesion in panel.conservacion.sesiones_en_riesgo" :key="sesion.id">
                            {{ sesion.titulo }} — {{ sesion.conservacion }}
                            [{{ sesion.estado_conservacion }}]
                        </li>
                    </ul>
                </div>

                <NuxtLink to="/paquetes" class="boton boton-secundario">Ir a medios y paquetes</NuxtLink>
            </section>

            <section class="tarjeta">
                <h3>Sesiones por estado</h3>
                <table>
                    <tbody>
                        <tr>
                            <th scope="row">Agendadas</th>
                            <td>{{ panel.sesiones_por_estado.agendada }}</td>
                        </tr>
                        <tr>
                            <th scope="row">Realizadas, sin elegir</th>
                            <td>{{ panel.sesiones_por_estado.realizada }}</td>
                        </tr>
                        <tr>
                            <th scope="row">Pendientes de selección</th>
                            <td>{{ panel.sesiones_por_estado.pendiente_seleccion }}</td>
                        </tr>
                        <tr>
                            <th scope="row">Entregadas</th>
                            <td>{{ panel.sesiones_por_estado.entregada }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <p v-else-if="mensajeDeError" class="sin-dato">{{ mensajeDeError }}</p>
    </div>
</template>

<script setup lang="ts">
import type { Panel } from '~/tipos/api'

/**
 * Panel: lo primero que se ve al entrar.
 *
 * POR QUE ESTA PANTALLA NO ES UN LISTADO DE SESIONES
 * --------------------------------------------------
 * Un listado se ordena por fecha y esconde lo que urge. Esta pantalla tiene
 * bloques, y el orden de los bloques es el orden en que se tienen que mirar las
 * cosas: primero lo que está esperando a una persona, después lo que depende de
 * un tercero, después la caja, y después los originales.
 *
 * LO QUE NO HACE
 * --------------
 * No dispara alertas ni manda avisos. Esta aplicación no tiene salida de correo
 * configurada, y mandar un aviso que nadie pidió es peor que no mandarlo. Ver
 * docs/08.
 */

definePageMeta({
    middleware: 'auth',
})

const cargando = ref(true)
const panel = ref<Panel | null>(null)
const mensajeDeError = ref<string | null>(null)

try {
    panel.value = await $fetch<Panel>('/api/panel')
} catch {
    mensajeDeError.value = 'No se pudo cargar el panel. Vuelva a intentar.'
} finally {
    cargando.value = false
}

/** Un número en pesos, sin decimales: los montos son enteros. */
function formatear(monto: number): string {
    return new Intl.NumberFormat('es-CL').format(monto)
}
</script>