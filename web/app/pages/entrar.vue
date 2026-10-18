<template>
    <div class="pantalla">
        <div class="tarjeta">
            <h1>Entrar al estudio</h1>
            <p class="tenue">
                Elija el estudio y siga el ingreso en el proveedor de identidad de Hefesto.
                No hay contraseñas en esta aplicación.
            </p>

            <div v-if="mensajeDeError" class="aviso aviso-error" role="alert">
                {{ mensajeDeError }}
            </div>

            <p v-if="estudios.length === 0 && !mensajeDeError" class="cargando">
                Buscando estudios...
            </p>

            <p v-else-if="estudios.length === 0" class="vacio">
                No hay estudios activos para mostrar. Si cree que debería haber alguno, hable con
                quien administra la instalación.
            </p>

            <ul v-else class="sin-dato" style="list-style: none; padding: 0; text-align: left">
                <li v-for="estudio in estudios" :key="estudio.slug" style="margin-bottom: 8px">
                    <button
                        type="button"
                        class="boton"
                        :disabled="entrandoEn === estudio.slug"
                        @click="entrar(estudio.slug)"
                    >
                        {{
                            entrandoEn === estudio.slug
                                ? 'Redirigiendo...'
                                : `Entrar a ${estudio.nombre}`
                        }}
                    </button>
                </li>
            </ul>

            <!--
                La lista de estudios sale de `vw_estudios_publicas`, que expone
                SOLO `slug`, `nombre` y `activo`. Antes del ingreso no hay motivo
                para que el navegador vea el RUT de nadie, la dirección, el correo
                ni el teléfono. Ver docs/03.
            -->
            <p class="tenue">
                Solo se muestra el nombre de los estudios activos. Esa restricción la impone la
                base de datos, no esta pantalla.
            </p>
        </div>
    </div>
</template>

<script setup lang="ts">
import type { EstudioPublico } from '~/tipos/api'

/**
 * Pantalla de ingreso.
 *
 * POR QUE NO HAY FORMULARIO DE USUARIO Y CONTRASEÑA
 * -----------------------------------------------
 * La identidad la emite Authentik con OpenID Connect. Esta aplicación no resuelve
 * contraseñas, segundo factor, recuperación ni bloqueo de cuentas, y reimplementar
 * eso sería peor en todos los sentidos. Ver docs/01 y docs/07.
 *
 * POR QUE EL ESTUDIO SE ELIJE AQUÍ Y NO EN AUTHENTIK
 * -------------------------------------------------
 * Porque el grupo del token dice a qué estudio pertenece la persona. La pantalla
 * solo validaría contra la vista pública de estudios y después redirige; si el
 * estudio no existe, se corta acá y no se manda a nadie a una pantalla de error de
 * vuelta. Ver `AuthController::entrar`.
 */

definePageMeta({
    layout: 'default',
})

const estudios = ref<EstudioPublico[]>([])
constmensajeDeError = ref<string | null>(null)
const entrandoEn = ref<string | null>(null)

const ruta = useRoute()

// Un error puede venir en la dirección cuando el proveedor rebota el ingreso.
onMounted(() => {
    if (typeof ruta.query.error === 'string') {
        mensajeDeError.value = ruta.query.error
    }
})

const { data } = await useFetch<{ estudios: EstudioPublico[] }>('/api/auth/estudios')

watchEffect(() => {
    if (data.value?.estudios) {
        estudios.value = data.value.estudios
    }
})

async function entrar(slug: string) {
    entrandoEn.value = slug
    mensajeDeError.value = null

    try {
        const respuesta = await $fetch<{ redirigir_a: string }>(`/api/auth/entrar/${slug}`)

        await navigateTo(respuesta.redirigir_a, { external: true })
    } catch {
        mensajeDeError.value = 'No se pudo iniciar el ingreso. Intente de nuevo.'
        entrandoEn.value = null
    }
}
</script>