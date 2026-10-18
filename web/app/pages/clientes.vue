<template>
    <div>
        <h2>Clientes</h2>
        <p class="tenue">
            Las personas de las que son las fotos. Esta ficha no la ve todo el estudio: quien puede
            ver fotos de los hijos de una familia es quien la persona autorizó para eso. Ver docs/07.
        </p>

        <div class="tarjeta">
            <div class="campo">
                <label for="buscar">Buscar por nombre, apellido o RUT</label>
                <input id="buscar" v-model="busqueda" @input="buscar" />
            </div>
        </div>

        <div v-if="mensaje" class="aviso aviso-ok" role="status">{{ mensaje }}</div>
        <div v-if="error" class="aviso aviso-error" role="alert">{{ error }}</div>

        <p v-if="cargando" class="cargando">Cargando los clientes...</p>
        <p v-else-if="clientes.length === 0" class="vacio">
            No hay clientes que coincidan con la búsqueda.
        </p>

        <table v-else>
            <thead>
                <tr>
                    <th scope="col">Nombre</th>
                    <th scope="col">RUT</th>
                    <th scope="col">Teléfono</th>
                    <th scope="col">Correo</th>
                    <th scope="col">Estado</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="cliente in clientes" :key="cliente.id">
                    <td>{{ cliente.nombre_completo }}</td>
                    <td>{{ cliente.rut ?? '—' }}</td>
                    <td>{{ cliente.telefono ?? '—' }}</td>
                    <td>{{ cliente.correo ?? '—' }}</td>
                    <td>
                        <span v-if="cliente.activo === false" class="estado estado-vencido">
                            Desactivado
                        </span>
                        <span v-else class="estado estado-entregada">Activo</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <section class="tarjeta">
            <h3>Registrar una persona</h3>

            <form class="columnas" @submit.prevent="guardar">
                <div class="campo">
                    <label for="nombre">Nombre</label>
                    <input id="nombre" v-model="formulario.nombre" required maxlength="160" />
                </div>
                <div class="campo">
                    <label for="apellido">Apellido</label>
                    <input id="apellido" v-model="formulario.apellido" required maxlength="160" />
                </div>
                <div class="campo">
                    <label for="rut">RUT</label>
                    <input id="rut" v-model="formulario.rut" maxlength="16" placeholder="123456789" />
                    <p class="tenue">
                        Se puede escribir con puntos y guion: el sistema los quita y comprueba el
                        dígito verificador.
                    </p>
                </div>
                <div class="campo">
                    <label for="telefono">Teléfono</label>
                    <input id="telefono" v-model="formulario.telefono" maxlength="32" />
                </div>
                <div class="campo">
                    <label for="correo">Correo</label>
                    <input id="correo" v-model="formulario.correo" type="email" maxlength="160" />
                </div>

                <div>
                    <button type="submit" class="boton" :disabled="enviando">
                        {{ enviando ? 'Guardando...' : 'Guardar' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>

<script setup lang="ts">
import type { Cliente } from '~/tipos/api'

/**
 * Ficha de clientes.
 *
 * POR QUE NO SE MUESTRAN LAS FOTOS EN ESTA LISTA
 * ---------------------------------------------
 * Porque son dos permisos distintos. Ver una ficha con nombre, RUT y teléfono es una
 * cosa; abrir los originales de las sesiones es otra, y queda en
 * `usuarios.puede_ver_archivos` con su propia bitácora. Ver docs/07.
 *
 * POR QUÉ DESACTIVAR Y NO BORRAR
 * -----------------------------
 * Una persona que contrata hoy puede volver en tres años, y para entonces su
 * ficha tiene que seguir ahí con sus sesiones. Por eso el listado muestra el
 * estado en vez de esconder la fila. Ver docs/02.
 */

definePageMeta({
    middleware: 'auth',
})

const busqueda = ref('')
const cargando = ref(true)
const enviando = ref(false)
const clientes = ref<Cliente[]>([])
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)

const formulario = reactive({
    nombre: '',
    apellido: '',
    rut: '',
    telefono: '',
    correo: '',
})

let temporizador: ReturnType<typeof setTimeout> | undefined

async function cargar() {
    cargando.value = true

    try {
        const respuesta = await $fetch<{ clientes: Cliente[] }>('/api/clientes', {
            query: busqueda.value === '' ? {} : { buscar: busqueda.value },
        })

        clientes.value = respuesta.clientes
    } finally {
        cargando.value = false
    }
}

/**
 * La búsqueda espera a que se deje de escribir.
 *
 * Sin este retardo, cada tecla abre una petición y la lista parpadea. No es un
 * detalle de gusto: en una lista de clientes, ir y volver rápido hace que la
 * pantalla muestre resultados viejos como si fueran nuevos.
 */
function buscar() {
    if (temporizador !== undefined) {
        clearTimeout(temporizador)
    }

    temporizador = setTimeout(() => {
        void cargar()
    }, 300)
}

async function guardar() {
    enviando.value = true
    mensaje.value = null
    error.value = null

    try {
        await $fetch('/api/clientes', {
            method: 'POST',
            body: {
                nombre: formulario.nombre,
                apellido: formulario.apellido,
                rut: formulario.rut === '' ? null : formulario.rut,
                telefono: formulario.telefono === '' ? null : formulario.telefono,
                correo: formulario.correo === '' ? null : formulario.correo,
            },
        })

        mensaje.value = 'Persona registrada.'

        formulario.nombre = ''
        formulario.apellido = ''
        formulario.rut = ''
        formulario.telefono = ''
        formulario.correo = ''

        await cargar()
    } catch (excepcion) {
        error.value = mensajeDe(excepcion)
    } finally {
        enviando.value = false
    }
}

function mensajeDe(excepcion: unknown): string {
    const cuerpo = (excepcion as { data?: { mensaje?: string } })?.data

    return cuerpo?.mensaje ?? 'No se pudo guardar la ficha.'
}

await cargar()
</script>