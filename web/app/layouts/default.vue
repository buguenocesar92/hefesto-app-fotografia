<template>
    <div class="pantalla">
        <header class="tarjeta">
            <div class="fila">
                <div>
                    <h1>Estudio fotográfico</h1>
                    <p class="tenue">
                        Contratos con paquete, fechas prometidas y conservación de los originales.
                    </p>
                </div>
                <div v-if="usuario" class="fila">
                    <span class="tenue">
                        {{ usuario.nombre }} · {{ usuario.rol_etiqueta }}
                        <template v-if="usuario.estudio"> · {{ usuario.estudio.nombre_corto }}</template>
                    </span>
                    <button type="button" class="boton boton-secundario" @click="salir">
                        Salir
                    </button>
                </div>
            </div>
        </header>

        <!--
            Las secciones se esconden con las CAPACIDADES del servidor, nunca con
            el rol. Ocultar un botón no protege nada: alguien puede llamar a la API
            directamente, y por eso cada ruta vuelve a preguntar a su Policy.
            Ver docs/03.
        -->
        <nav v-if="usuario" class="tarjeta" aria-label="Secciones del estudio">
            <div class="fila">
                <div class="fila">
                    <NuxtLink to="/" class="boton boton-secundario">Panel</NuxtLink>
                    <NuxtLink to="/agenda" class="boton boton-secundario">Agenda</NuxtLink>
                    <NuxtLink to="/sesiones" class="boton boton-secundario">Sesiones</NuxtLink>
                    <NuxtLink to="/seleccion" class="boton boton-secundario">Selección</NuxtLink>
                </div>
                <div class="fila">
                    <NuxtLink to="/clientes" class="boton boton-secundario">Clientes</NuxtLink>
                    <NuxtLink to="/contratos" class="boton boton-secundario">Contratos</NuxtLink>
                    <NuxtLink to="/entregas" class="boton boton-secundario">Entregas</NuxtLink>
                    <NuxtLink to="/saldos" class="boton boton-secundario">Saldos</NuxtLink>
                </div>
                <div class="fila">
                    <NuxtLink to="/pedidos-laboratorio" class="boton boton-secundario">
                        Laboratorio
                    </NuxtLink>
                    <NuxtLink to="/paquetes" class="boton boton-secundario">Paquetes</NuxtLink>
                </div>
            </div>
        </nav>

        <main>
            <NuxtPage />
        </main>

        <footer class="tenue" style="margin-top: 24px">
            Software propietario de Hefesto Studio. Las fechas que se muestran son las que se
            escribieron al agendar, no las que calcula el sistema.
        </footer>
    </div>
</template>

<script setup lang="ts">
/**
 * Marco de la aplicación.
 *
 * LA BARRA DE ARRIBA ES LO QUE SE VE PRIMERO
 * -------------------------------------------
 * En un estudio se abre la pantalla con una pregunta en la cabeza: ¿qué hay para
 * hoy? Por eso el menú pone primero la agenda y las sesiones, que son las que
 * tienen fecha, y deja contratos y saldos para el final del día.
 *
 * LO QUE NO HACE
 * --------------
 * No decide nada. Solo esconde lo que la persona no puede hacer según las
 * capacidades que le devolvió el servidor. La comprobación de verdad está en la
 * API, y volver a preguntar acá sería una segunda fuente de verdad que algún día
 * se desincroniza de la primera. Ver docs/03.
 */

const { usuario, cargar, cerrarSesion } = useSesion()

await cargarSesion()

/**
 * En el servidor se resuelve una sola vez. Si no hay sesión, `usuario` queda en
 * `null` y la pantalla de ingreso se encarga de redirigir; el marco igual se
 * dibuja, sin barra de sesión, para que el ingreso no aparezca dentro de una
 * aplicación que parece abierta.
 */
async function cargarSesion() {
    if (import.meta.server && usuario.value !== null) {
        return
    }

    await cargar()
}

async function salir() {
    await cerrarSesion()
}
</script>