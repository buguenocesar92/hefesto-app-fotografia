// Configuración de la interfaz de Fotografía (Nuxt 4 con render en el servidor).
//
// Las versiones están fijadas por rango mayor, no exacto, a propósito: una
// corrección de seguridad dentro de la rama compatible no debería exigir rehacer
// la imagen. El digesto exacto queda en deploy/web/Dockerfile.

export default defineNuxtConfig({
    // La interfaz se renderiza en el servidor. Motivo concreto: la agenda y la
    // ficha de la persona de la que son las fotos no deben quedar en el HTML de
    // una página pública, y una pantalla que se dibuja en el servidor no depende
    // de que el JavaScript haya cargado para no filtrar nada.
    ssr: true,

    compatibilityDate: '2026-10-18',

    devtools: { enabled: false },

    runtimeConfig: {
        // URL interna de la API de Laravel. Solo la usa el servidor de Nuxt:
        // nunca viaja al navegador.
        apiBase: process.env.NUXT_API_BASE || 'http://api:8000',

        public: {
            // Días hacia atrás que se ofrecen como período en la pantalla de
            // saldos. Es una ayuda de navegación, no una regla de negocio.
            diasSaldos: Number(process.env.NUXT_DIAS_SALDOS || 90),
        },
    },

    css: ['~/assets/css/main.css'],

    app: {
        head: {
            htmlAttrs: { lang: 'es-CL' },
            title: 'Fotografía',
            meta: [
                { charset: 'utf-8' },
                { name: 'viewport', content: 'width=device-width, initial-scale=1' },
                // La aplicación no se indexa: no es un sitio público, y las
                // pantallas tienen nombres de personas y de familias.
                { name: 'robots', content: 'noindex, nofollow' },
                { name: 'referrer', content: 'same-origin' },
            ],
        },
    },

    nitro: {
        // El BFF reenvía la cookie de sesión de Laravel. Por eso la aplicación
        // de Nuxt NO necesita ningún token propio.
        compressPublicAssets: true,
    },

    routeRules: {
        // Nada que dependa de una sesión se cachea en un proxy.
        '/**': { headers: { 'cache-control': 'no-store' } },
        '/entrar': { ssr: true },
    },
})