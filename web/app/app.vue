<template>
    <NuxtLayout>
        <NuxtPage />
    </NuxtLayout>
</template>

<script setup lang="ts">
/**
 * Raíz de la aplicación.
 *
 * POR QUE NO HAY NADA ACÁ
 * -----------------------
 * La raíz solo monta el marco y la página. Todo lo que decide algo vive en las
 * páginas y en el composable `useSesion`, para que no haya dos lugares que
 * puedan contradecirse sobre quién está entrando.
 *
 * La aplicación se renderiza en el servidor: la agenda y la ficha de una persona
 * no deben quedar en el HTML de una página pública, y una pantalla que se dibuja
 * en el servidor no depende de que el JavaScript haya cargado para no filtrar
 * nada. Ver `nuxt.config.ts`.
 */
</script>