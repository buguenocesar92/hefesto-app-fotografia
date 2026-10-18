/**
 * Composable de sesión.
 *
 * SOLO LECTURA Y NAVEGACIÓN
 * -------------------------
 * Los permisos vienen de la API. Este archivo esconde botones, no protege datos.
 * Que un botón no aparezca no impide que alguien llame a la API directamente, y
 * por eso cada ruta del servidor vuelve a preguntar al servidor. Ver docs/03.
 */

import type { UsuarioSesion } from '~/tipos/api'

export function useSesion() {
    const usuario = useState<UsuarioSesion | null>('usuario', () => null)

    /**
     * Pide los datos de la persona al servidor.
     *
     * Un 401 o un 404 se tratan igual: sin sesión no hay nada que mostrar, y la
     * pantalla de ingreso es el único lugar donde tiene sentido estar.
     */
    async function cargar(): Promise<UsuarioSesion | null> {
        try {
            usuario.value = await $fetch<UsuarioSesion>('/api/auth/usuario')
        } catch {
            usuario.value = null
        }

        return usuario.value
    }

    async function cerrarSesion(): Promise<void> {
        await $fetch('/api/auth/salir', { method: 'POST' }).catch(() => undefined)

        usuario.value = null

        await navigateTo('/entrar')
    }

    const capacidades = computed(() => usuario.value?.capacidades ?? null)

    /**
     * Cada permiso por separado.
     *
     * Se nombran por lo que hacen y no por el rol, porque el rol es cómo se
     * organiza el estudio y la capacidad es lo que la persona puede hacer. Mañana
     * el rol puede cambiar sin que cambie lo que se necesita.
     */
    const puedeVerAgenda = computed(() => capacidades.value?.ver_agenda === true)
    const puedeGestionarContratos = computed(() => capacidades.value?.gestionar_contratos === true)
    const puedeAdministrarPaquetes = computed(() => capacidades.value?.administrar_paquetes === true)
    const puedeRegistrarSeleccion = computed(() => capacidades.value?.registrar_seleccion === true)
    const puedeDarPorEntregada = computed(() => capacidades.value?.dar_por_entregada === true)
    const puedeGestionarLaboratorio = computed(
        () => capacidades.value?.gestionar_pedidos_laboratorio === true,
    )
    const puedeVerSaldos = computed(() => capacidades.value?.ver_saldos === true)
    const puedeRegistrarAbonos = computed(() => capacidades.value?.registrar_abonos === true)
    const puedeVerArchivos = computed(() => capacidades.value?.ver_archivos === true)
    const puedeAdministrar = computed(() => capacidades.value?.administra_fotografia === true)

    return {
        usuario,
        cargar,
        cerrarSesion,
        puedeVerAgenda,
        puedeGestionarContratos,
        puedeAdministrarPaquetes,
        puedeRegistrarSeleccion,
        puedeDarPorEntregada,
        puedeGestionarLaboratorio,
        puedeVerSaldos,
        puedeRegistrarAbonos,
        puedeVerArchivos,
        puedeAdministrar,
    }
}