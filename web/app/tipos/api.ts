/**
 * Los tipos que viajan entre la API y la interfaz.
 *
 * POR QUÉ ESTE ARCHIVO ES LA FUENTE
 * ---------------------------------
 * Porque las respuestas de la API son la forma en que la pantalla ve el mundo. Si
 * dos pantallas interpretan el mismo campo de dos maneras, el error aparece en la
 * cifra que se le muestra a una familia, y nadie sabe de dónde salió.
 *
 * Y porque varios campos SON `null` a propósito: el plazo de entrega, el plazo de
 * verificación del medio y el formato de impresión pueden no estar configurados.
 * Un tipo que dijera `string` obligaría a la pantalla a inventar un valor para
 * poder compilar, y un valor inventado en pantalla es peor que un hueco. Ver
 * docs/06.
 */

// -----------------------------------------------------------------------------
// Identidad
// -----------------------------------------------------------------------------

export interface Capacidades {
    ver_agenda: boolean
    ver_contratos: boolean
    gestionar_contratos: boolean
    administrar_paquetes: boolean
    gestionar_sesiones: boolean
    registrar_seleccion: boolean
    dar_por_entregada: boolean
    gestionar_pedidos_laboratorio: boolean
    administrar_laboratorios: boolean
    registrar_abonos: boolean
    ver_saldos: boolean
    anular_abonos: boolean
    ver_archivos: boolean
    administra_fotografia: boolean
    ver_auditoria: boolean
}

export interface UsuarioSesion {
    id: string
    nombre: string
    correo: string
    rol: 'recepcion' | 'fotografo' | 'administracion'
    rol_etiqueta: string
    /** Permiso sobre los originales. No se deduce del rol. Ver docs/07. */
    puede_ver_archivos: boolean
    estudio: { id: string; nombre: string; nombre_corto: string } | null
    capacidades: Capacidades
}

export interface EstudioPublico {
    slug: string
    nombre: string
}

// -----------------------------------------------------------------------------
// Catálogos
// -----------------------------------------------------------------------------

export interface Paquete {
    id: string
    codigo: string
    nombre: string
    descripcion: string | null
    precio_referencia: number
}

export interface Laboratorio {
    id: string
    nombre: string
    contacto: string | null
    telefono: string | null
    correo?: string | null
}

export interface Cliente {
    id: string
    nombre: string
    apellido: string
    nombre_completo: string
    rut: string | null
    telefono: string | null
    correo: string | null
    activo?: boolean
}

// -----------------------------------------------------------------------------
// Contratos y saldo
// -----------------------------------------------------------------------------

export interface LineaContrato {
    id: string
    tipo: string
    tipo_etiqueta: string
    concepto: string
    unidad_minutos: number | null
    cantidad_pactada: number
    cantidad_entregada: number
    cantidad_pendiente: number
    precio_unitario: number
    valor_pactado: number
    valor_pendiente: number
    completa: boolean
}

export interface LineaDesglose {
    linea_id: string | null
    concepto: string
    tipo: string | null
    /** `minutos`, `unidades`, `unidades impresas`, `álbumes`, `null`. */
    unidad: string | null
    cantidad_pactada: number
    cantidad_entregada: number
    cantidad_pendiente: number
    unidades_de_exceso: number
    precio_unitario: number
    pactado: number
    entregado: number
    valor_pendiente: number
    completa: boolean
}

export interface AbonoDesglose {
    abono_id: string | null
    codigo: string
    fecha: string
    monto: number
    metodo: string
    referencia: string | null
    deja_rastro: boolean
}

/**
 * El desglose del saldo. NO es un número.
 *
 * Las ocho cifras existen porque son tres preguntas distintas: qué prometí, qué
 * entregué y qué me deben. Un total único no contesta ninguna de las tres.
 * Ver `App\Services\Cobranza\DesgloseDeSaldo`.
 */
export interface DesgloseSaldo {
    contrato_id: string
    codigo: string
    precio_contrato: number
    total_de_lineas: number
    total_entregado: number
    valor_por_entregar: number
    cantidad_pendiente: number
    total_abonado: number
    saldo_pendiente: number
    saldo_a_favor: number
    esta_todo_entregado: boolean
    tiene_saldo: boolean
    lineas: LineaDesglose[]
    abonos: AbonoDesglose[]
    /** Por qué falta algo, en palabras. Sin esto el saldo no se explica. */
    observaciones: string[]
}

export interface FilaSaldo {
    contrato_id: string
    codigo: string
    cliente: string | null
    estado: string
    estado_etiqueta: string
    precio_contrato: number
    total_entregado: number
    valor_por_entregar: number
    cantidad_pendiente: number
    total_abonado: number
    saldo_pendiente: number
    saldo_a_favor: number
    esta_todo_entregado: boolean
    observaciones: string[]
}

export interface Contrato {
    id: string
    codigo: string
    cliente_id: string
    cliente: string | null
    paquete_id: string | null
    fecha_contrato: string | null
    precio_total: number
    estado: string
    estado_etiqueta: string
    /** `null` significa "no hay plazo configurado". No es un error. */
    dias_promesa_entrega: number | null
    plazo_configurado: boolean
    fecha_cierre: string | null
    lineas: LineaContrato[]
    desglose: DesgloseSaldo | null
}

// -----------------------------------------------------------------------------
// Sesiones
// -----------------------------------------------------------------------------

export type EstadoSesion = 'agendada' | 'realizada' | 'pendiente_seleccion' | 'entregada'

export interface SesionAgenda {
    id: string
    titulo: string
    tipo: string
    tipo_etiqueta: string
    lugar: string | null
    cliente: string | null
    fotografo: string | null
    fecha_prometida: string | null
    fecha_realizada: string | null
    fecha_prometida_entrega: string | null
    estado: EstadoSesion
    estado_etiqueta: string
    duracion_minutos: number | null
    /** Un cumpleaños o un bautismo pueden incluir a menores. Ver docs/07. */
    puede_incluir_menores: boolean
}

export interface SesionDetalle {
    id: string
    titulo: string
    tipo: string
    tipo_etiqueta: string
    lugar: string | null
    cliente_id: string
    cliente: string | null
    contrato_id: string | null
    fotografo_id: string | null
    fecha_prometida: string | null
    fecha_realizada: string | null
    fecha_prometida_entrega: string | null
    estado: EstadoSesion
    estado_etiqueta: string
    duracion_minutos: number | null
    conservacion_id: string | null
    tiene_seleccion_confirmada: boolean
    /** Por qué el botón de entregar está deshabilitado. En palabras. */
    motivo_de_no_poder_entregar: string | null
    transiciones_posibles: EstadoSesion[]
}

// -----------------------------------------------------------------------------
// Selección
// -----------------------------------------------------------------------------

export interface FotoGaleria {
    id: string
    nombre_archivo: string
    extension: string | null
    bytes: number | null
    elegida: boolean
}

export interface Seleccion {
    id: string
    estado: string
    estado_etiqueta: string
    cantidad_seleccionada: number
    fecha_confirmacion: string | null
    confirmada: boolean
}

export interface VistaSeleccion {
    sesion: {
        id: string
        titulo: string
        estado: EstadoSesion
        estado_etiqueta: string
        fecha_prometida: string | null
        fecha_prometida_entrega: string | null
        cliente: string | null
    }
    fotos: FotoGaleria[]
    conteo: { total: number; elegidas: number }
    seleccion: Seleccion | null
    motivo_de_no_poder_entregar: string | null
    exceso: { tope: number | null; configurado: boolean }
}

// -----------------------------------------------------------------------------
// Laboratorio
// -----------------------------------------------------------------------------

export interface PedidoLaboratorio {
    id: string
    codigo: string
    laboratorio: string | null
    sesion_id: string | null
    estado: string
    estado_etiqueta: string
    fecha_envio: string | null
    /** El compromiso con el CLIENTE. `null` si no hay plazo configurado. */
    fecha_prometida_recepcion: string | null
    /** Lo que se le pidió al laboratorio. Otro compromiso. */
    dias_plazo_laboratorio: number | null
    fecha_recepcion_real: string | null
    costo_total: number
    cantidad_pedida: number
    cantidad_recibida: number
    tiene_faltantes: boolean
    vencido: boolean
    plazo_configurado: boolean
}

// -----------------------------------------------------------------------------
// Conservación
// -----------------------------------------------------------------------------

export interface Conservacion {
    id: string
    nombre: string
    tipo_medio: string
    ubicacion: string
    estado: string
    estado_etiqueta: string
    operativa: boolean
    ultima_verificacion_en: string | null
    /** `null` cuando nunca se verificó. No es cero: es que no hay fecha. */
    dias_sin_verificar: number | null
    necesita_revision: boolean
    sesiones_asignadas: number
}

export interface InformeConservacion {
    dias_max_sin_verificar: number | null
    plazo_configurado: boolean
    pendientes: Array<{
        id: string
        nombre: string
        estado: string
        estado_etiqueta: string
        tipo_medio: string
        ubicacion: string
        sesiones_asignadas: number
        dias_sin_verificar: number | null
        ultima_verificacion_en: string | null
    }>
    sesiones_en_riesgo: Array<{
        id: string
        titulo: string
        fecha_prometida: string | null
        conservacion: string | null
        estado_conservacion: string | null
    }>
}

// -----------------------------------------------------------------------------
// Panel
// -----------------------------------------------------------------------------

export interface Panel {
    pendientes_de_seleccion: Array<{
        id: string
        titulo: string
        tipo: string
        fecha_prometida: string | null
        fecha_prometida_entrega: string | null
        atrasada: boolean
    }>
    sesiones_atrasadas: number
    pedidos_laboratorio_vencidos: Array<{
        id: string
        codigo: string
        laboratorio: string | null
        fecha_prometida_recepcion: string | null
        estado: string
    }>
    conservacion: InformeConservacion
    sesiones_por_estado: Record<EstadoSesion, number>
    caja: {
        contratos_abiertos: number
        total_por_cobrar: number
        plazo_de_verificacion_configurado: boolean
        resultado_de_verificacion: string
    }
}