# 02 · Modelo de datos

Veintitrés tablas: diecisiete del dominio y seis del esqueleto de Laravel. Este
documento dice qué crea cada migración, qué ató y, sobre todo, **qué quedó sin
definir**.

---

## El mapa

```
  estudios  (sin RLS: es el tenant)
     |
     +-- usuarios
     +-- clientes ----+
     +-- paquetes     |
     +-- contratos <--+   linea_contrato
     |      |              ^
     |      +-- abonos     |
     +-- sesiones <-------+
     |      |
     |      +-- fotos ---> seleccion_detalles
     |      |                  ^
     |      +-- selecciones --+
     |      |
     |      +-- pedidos_laboratorio --> pedido_laboratorio_items
     |             |
     +-- laboratorios
     +-- conservaciones --> conservacion_verificaciones
            ^
            |
        sesiones.conservacion_id

  accesos_archivo  (bitacora aparte, conexion `auditoria`)
```

Las flechas son claves foráneas. Todas las de negocio apuntan a `estudios` por
`tenant_id` con `cascadeOnDelete`, y todas las tablas de negocio llevan
`tenant_id`.

---

## Las doce migraciones

Todas del `2026_10_18`, en el orden en que corren:

| # | Crea |
|---|---|
| 000001 | `estudios`, y le agrega `nombre_corto`, `direccion`, `telefono`, `correo` |
| 000002 | `sesion_web`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches` |
| 000003 | `usuarios` |
| 000004 | `clientes`, `paquetes` |
| 000005 | `contratos`, `lineas_contrato` |
| 000006 | `sesiones` |
| 000007 | `fotos`, `selecciones`, `seleccion_detalles` |
| 000008 | `laboratorios`, `pedidos_laboratorio`, `pedido_laboratorio_items` |
| 000009 | `abonos` |
| 000010 | `conservaciones`, `conservacion_verificaciones`, `accesos_archivo`, y la FK `sesiones.conservacion_id` |
| 000011 | la vista `vw_estudios_publicas` |
| 000012 | RLS sobre las dieciséis tablas de negocio |

### Una decisión que hay que conocer antes de tocar el esquema

**La tabla de sesión de Laravel se llama `sesion_web`, no `sesiones`.** En un
estudio una sesión es una toma de fotos con fecha prometida, y ese nombre ya
estaba tomado por el dominio. Renombrar la tabla del esqueleto es lo que permite
que la tabla del dominio se llame como corresponde. Está declarado en
`config/session.php`, en su migración y en el comentario de `bootstrap/app.php`.

Si alguna vez una consulta a `sesiones` devuelve las tablas de sesión en vez de las
tomas de fotos, el error está acá: hay dos tablas distintas y se llaman casi igual.

---

## Las diecisiete tablas del dominio

### `estudios`

`id`, `slug` (único, 64), `nombre`, `nombre_corto`, `direccion`, `telefono`,
`correo`, `activo`.

**Es la única tabla del dominio sin `tenant_id`**, porque es el tenant. No lleva
RLS y eso es correcto: se consulta antes de que exista sesión, es la lista del
selector de ingreso. Ver [03](03-MULTITENANCY-Y-ROLES.md).

### `usuarios`

`tenant_id`, `nombre`, `correo`, `rol` (24), `puede_ver_archivos` (por defecto
`false`), `authentik_id`, `password`, `activo`.

`puede_ver_archivos` es **por cuenta, no por rol**: una recepción que necesita
ver originales la tiene; un fotógrafo que no, no. El rol dice qué puede hacer
con los datos; este permiso dice si puede abrir el archivo.

### `clientes`

`tenant_id`, `nombre`, `apellido`, `rut` (16), `telefono`, `correo`, `notas`,
`activo`.

Una persona que contrata hoy puede volver en tres años y su historial tiene que
seguir ahí. Por eso `activo` en vez de borrado. La ruta HTTP se llama
`.../desactivar` y es un `POST`, para que el verbo no diga "borrar".

### `paquetes`

`tenant_id`, `codigo` (32), `nombre`, `descripcion`, `precio_referencia`,
`activo`.

`precio_referencia` es un dato del estudio, no un precio del software. No hay
tabla de formatos de impresión con precio porque la impresión se **encarga** a
un laboratorio externo. Ver la sección de impresión más abajo.

### `contratos`

`tenant_id`, `cliente_id`, `paquete_id`, `codigo`, `fecha_contrato`,
`precio_total`, `estado` (24), `dias_promesa_entrega`, `notas`.

`dias_promesa_entrega` es **por contrato**, no por instalación. Es la promesa que
el estudio le hizo a esa persona en esa fecha. Por eso no tiene valor por omisión:
ver [05](05-CONFIGURACION.md).

### `lineas_contrato`

`tenant_id`, `contrato_id`, `tipo` (24), `concepto`, `unidad_minutos`,
`cantidad_pactada`, `cantidad_entregada`, `precio_unitario`, `detalle`.

`tipo` es uno de `sesion`, `foto_entregada`, `impresion`, `album`,
`archivo_digital`, `adicional`.

`unidad_minutos` es lo que traduce "sesión de dos horas" a la agenda: la duración
de una sesión **no sale de la configuración, sale de la línea del contrato que la
promete**.

`cantidad_pactada` y `cantidad_entregada` son la pareja que hace posible la
entrega parcial. Ver la sección siguiente.

### `sesiones`

`tenant_id`, `contrato_id`, `cliente_id`, `fotografo_id`, `tipo`, `titulo`,
`lugar`, `fecha_prometida`, `fecha_realizada`, `estado`, `duracion_minutos`,
`fecha_prometida_entrega`, `notas`. Y `conservacion_id`, que la migración 000010
agrega al final.

**Dos fechas y no una.** `fecha_prometida` es la que se le dijo a la persona y es
un compromiso. `fecha_realizada` es la que pasó y puede ser distinta. Sobrescribir
la primera con la segunda borra el compromiso, que es exactamente lo que no se
puede perder cuando alguien pregunta en marzo por qué no le llegó.

### `fotos`

`tenant_id`, `sesion_id`, `nombre_archivo`, `extension`, `bytes`,
`ruta_relativa`.

Es el **inventario**, no el contenido. Dice cuántos archivos hay, cuánto pesan y
dónde se declaran que están. El binario vive en el medio declarado en
`conservaciones`.

### `selecciones` y `seleccion_detalles`

`selecciones`: `tenant_id`, `sesion_id`, `cliente_id`, `estado`,
`cantidad_seleccionada`, `notas`.

`seleccion_detalles`: `tenant_id`, `seleccion_id`, `foto_id`, `elegida`.

Una selección viva por sesión (relación `HasOne`). El detalle dice **qué** eligió
la persona, que es lo que después manda a imprimir.

### `laboratorios`, `pedidos_laboratorio`, `pedido_laboratorio_items`

`laboratorios`: `tenant_id`, `nombre`, `contacto`, `telefono`, `correo`, `activo`.

`pedidos_laboratorio`: `tenant_id`, `laboratorio_id`, `sesion_id`, `contrato_id`,
`codigo`, `fecha_envio`, `fecha_prometida_recepcion`, `dias_plazo_laboratorio`,
`fecha_recepcion_real`, `estado`, `costo_total`, `observaciones`.

`pedido_laboratorio_items`: `tenant_id`, `pedido_id`, `seleccion_id`, `tipo`,
`formato`, `cantidad_pedida`, `cantidad_recibida`, `precio_unitario`, `detalle`.

El contenido del pedido **se congela al enviarlo**: después de eso hay un trabajo
en curso en otro lado y cambiar el pedido no cambia lo que ya se imprimió.

`dias_plazo_laboratorio` es por pedido, porque el plazo se pacta con cada
laboratorio y cambia con la temporada.

### `abonos`

`tenant_id`, `contrato_id`, `registrado_por`, `codigo`, `fecha`, `monto`,
`metodo` (24), `referencia`, `notas`.

`referencia` es lo que el estudio escribe para poder ubicar el depósito después.
Sin eso, un abono es un número sin dueño.

### `conservaciones`, `conservacion_verificaciones`, `accesos_archivo`

`conservaciones`: `tenant_id`, `nombre`, `tipo_medio` (32), `ubicacion`,
`estado` (32), `ultima_verificacion_en`, `bytes_declarados`, `sesiones_asignadas`,
`notas`.

`tipo_medio` es una lista **cerrada**: `disco_local`, `red_area_local`,
`almacenamiento_nube`, `cinta`. Declarada en PHP
(`Conservacion::TIPOS_DE_MEDIO`), no en la base. Agregar un medio nuevo es una
decisión sobre cómo se custodia un archivo que pertenece a otras personas, y no
algo que se haga desde una pantalla. Es lo que impide que alguien escriba "en el
escritorio de la recepción" en el campo de ubicación.

`estado` es `activa`, `desconectada`, `danada` o `destruida`.

`ultima_verificacion_en` es una **copia** de la fecha de la última verificación
correcta, copiada desde `conservacion_verificaciones` para que la pantalla de
pendientes no tenga que traer todo el historial. La fuente sigue siendo la tabla
de verificaciones.

**Por qué esto no es una columna `ruta_del_disco` en `sesiones`.** Un texto con
la ruta del disco no responde a la única pregunta que importa: ¿el disco sigue
vivo? El texto va a seguir ahí, diciendo que todo está bien, el día después de
que el disco se haya caído. Y esa es la pérdida más grande del rubro: las
sesiones de tres años, perdidas de una vez. Ver [08](08-RESPALDOS.md).

`conservacion_verificaciones` guarda cada revisión con fecha, resultado
(`correcta`, `con_faltantes`, `no_se_pudo_verificar`), archivos revisados,
faltantes y quién la hizo. Sin historial no hay forma de demostrar que el estudio
cuidó lo que entregó.

`accesos_archivo` guarda cada apertura de un original, **permitida o denegada**,
con la dirección desde la que vino. Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

---

## Las seis tablas del esqueleto

`sesion_web`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`,
`job_batches`. No llevan `tenant_id` ni RLS: son del framework, no del dominio.

---

## Las cuatro reglas del modelo

### 1. Nada se borra

| En vez de | Se hace |
|---|---|
| borrar un cliente | `activo = false`, ruta `.../desactivar` |
| borrar un contrato | `estado = anulado` con motivo en `notas` |
| tirar un medio | `estado = destruida`, se deja de asignar |

El motivo es concreto: las fechas prometidas, los abonos y la custodia de los
originales son la evidencia con la que se responde un reclamo. Borrarlos deja
números que no cuadran.

### 2. La entrega parcial no cierra el contrato

`RegistradorDeEntrega::recalcularEstado()` solo marca el contrato como `cerrado`
cuando **todas** sus líneas tienen `cantidad_entregada >= cantidad_pactada`.

Una línea parcial sube su propio `cantidad_entregada` y deja el contrato
`activo`. El caso que lo define: se entregaron 80 de 100 fotos. El contrato sigue
abierto porque hay 20 fotos y un abono que todavía no se cobró. Cerrarlo ahí sería
inventar un final.

Además el registrador **rechaza entregar más de lo pactado** y rechaza líneas de
impresión o álbum sin selección confirmada. Ver [06](06-PRIMIER-CONTRATO.md).

### 3. El saldo se calcula sobre lo pactado

`CalculadoraDeSaldo` usa `contratos.precio_total`, no lo entregado.

Si el saldo se calculara sobre lo entregado, el abono inicial del 50% al firmar
no sería una deuda: sería un adelanto sobre un total de cero. Con el total
pactado, la firma ya genera una deuda real de la mitad, que es lo que hace que el
abono inicial tenga sentido.

El saldo **no** se guarda en la base: se calcula. Una columna `saldo` guardada se
desincroniza en cuanto hay un abono y se lee el número viejo.

### 4. `pendiente_seleccion` es un estado, no un error

Una sesión que se tomó y en la que la persona todavía no eligió está en
`pendiente_seleccion`. Es el estado normal, no una falla. En el CSS es un color de
trabajo, no el color de error.

`entregada` exige selección `confirmada`, comprobado por
`GestorDeEstadoDeSesion::exigirSeleccionConfirmada()` **en el momento de
escribir**, no solo al mostrar el botón. La misma condición se expone como
`motivo_de_no_poder_entregar` para deshabilitar el botón sin dejarlo mudo.

---

## Lo que quedó sin definir

Estas cuatro cosas están en `null` a propósito. Ninguna tiene un número inventado
en un valor por omisión, y la pantalla las declara como pendientes.

| Qué | Dónde | Por qué |
|---|---|---|
| Días de promesa de entrega | `contratos.dias_promesa_entrega` | Es una promesa comercial. Un bautismo y unos portraits de empresa no tienen el mismo plazo, y un estudio que promete rápido cobra distinto. |
| Días de plazo de laboratorio | `pedidos_laboratorio.dias_plazo_laboratorio` | Se pacta con cada laboratorio y cambia por temporada. |
| Días de espera de selección | `config.fotografia.seleccion.dias_para_seleccionar` | Depende del tipo de sesión. |
| Días sin verificar una conservación | `config.fotografia.conservacion.dias_max_sin_verificar` | Un número inventado produce avisos falsos que entrenan al equipo a ignorar el sistema. |
| Retención de la bitácora de accesos | `config.fotografia.conservacion_de_datos` | Es una decisión del cliente con su revisión legal, no del software. Ver [07](07-SEGURIDAD-Y-LEY-19628.md). |

`FOTOGRAFIA_EXCESO_SELECCION_SIN_PAGO` también nace en `null`: cuántas fotos se
pueden marcar por encima de las que el paquete promete depende de cada estudio.

---

© 2026 Hefesto Studio. Software propietario.