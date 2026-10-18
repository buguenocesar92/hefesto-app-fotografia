# 07 · Seguridad y Ley 19.628

Acá se guardan datos personales de clientes y, sobre todo, **fotos de personas**.
Y las fotos de menores lo son con más razón.

Este documento dice qué se guarda, quién puede verlo, qué queda anotado, y qué
decisiones tiene que tomar el estudio con su revisión legal.

> **Advertencia.** Este documento describe lo que hace el software. **No es una
> opinión legal.** La Ley 19.628 y su reglamento tienen interpretaciones que
> dependen de la situación concreta, y hay decisiones que solo el estudio con su
> abogado puede tomar. Lo que está marcado como pendiente acá está pendiente
> justamente porque no se quiso inventar.

---

## Qué es un dato personal en esta aplicación

La Ley 19.628 protege el dato **de una persona identificable**. En un estudio
fotográfico hay tres capas, y la tercera es la que casi nadie considera:

| Capa | Ejemplo | Qué es |
|---|---|---|
| **Datos de la persona que contrata** | nombre, RUT, teléfono, correo de quien firma | dato personal directo |
| **Datos de las personas fotografiadas** | los originales, y sobre todo los rostros | dato personal, y **biometría** por su naturaleza |
| **Datos de la sesión** | cuándo se tomó, dónde, quién estaba, qué se eligió | dato sobre la persona fotografiada |

La tercera capa es la que cambia el análisis. Un archivo de texto con el nombre de
alguien es un dato personal. **Una foto de un rostro es un dato personal más
sensible**, porque identifica de forma directa e involuntaria.

Y cuando la persona fotografiada es menor de edad, el análisis se endurece otra
vez. El sistema **no tiene** una noción de "foto de menor": no hay campo que lo
marque. Esa es una limitación real y hay que decirla acá.

---

## Qué se guarda

| Tabla | Qué contiene | Dato personal de |
|---|---|---|
| `clientes` | nombre, apellido, RUT, teléfono, correo, notas | quien contrata |
| `usuarios` | nombre, correo, rol | el equipo del estudio |
| `sesiones` | título, lugar, fechas, quién estuvo | **quien fue fotografiado**, a través de la sesión |
| `fotos` | nombre del archivo, bytes, ruta | el archivo, indirectamente |
| `selecciones` | qué eligió la persona | **quien fue fotografiado** |
| `conservacion_verificaciones` | quién revisó, cuándo | el equipo |
| `accesos_archivo` | quién abrió qué, desde qué dirección, permitido o denegado | el equipo, y las fotos a las que accedió |
| `abonos` | referencia del pago, notas | quien contrata |

**Lo que NO se guarda: el contenido de la foto en la base.** El binario vive en el
medio declarado en `conservaciones`, que es un disco o una red. Ver
[08](08-RESPALDOS.md).

---

## Las cuatro decisiones que hay que tomar

### 1. La finalidad declarada

La ley exige que la conservación de datos personales tenga una **finalidad
declarada**. En un estudio la finalidad es clara y es buena: entregar las fotos y
poder responder los reclamos. Lo que hay que escribir es la finalidad **por
escrito**, con la firma de quien autoriza.

El software no la declara por el estudio. Es una decisión del cliente.

### 2. Los plazos

Acá está lo más importante de este documento:

> **No hay ningún plazo configurado. Y no es un descuido.**

La aplicación tiene seis plazos que deberían tener número, y los seis nacen en
`null`:

| Plazo | Dónde | Por qué no tiene número |
|---|---|---|
| Retención de la bitácora de accesos | `FOTOGRAFIA_RETENCION_ACCESOS_ARCHIVO_DIAS` | No se pudo verificar el plazo que corresponde |
| Días de entrega prometida | `FOTOGRAFIA_DIAS_PROMESA_ENTREGA` | Es una promesa comercial, no un plazo legal |
| Días de plazo de laboratorio | `FOTOGRAFIA_DIAS_PROMESA_LABORATORIO` | Se pacta con cada laboratorio |
| Días de espera de selección | `FOTOGRAFIA_DIAS_PARA_SELECCION` | Depende del tipo de sesión |
| Días sin verificar una conservación | `FOTOGRAFIA_DIAS_MAX_SIN_VERIFICAR` | Un número inventado produce avisos falsos |
| Días entre verificaciones | `FOTOGRAFIA_DIAS_ENTRE_VERIFICACIONES` | Este sí tiene 30, es operativo, no legal |

Con un plazo en `null`, la aplicación **no purga nada por su cuenta**. Y eso es lo
correcto: un número inventado que se cumpliera solo borraría datos que alguien
todavía necesita.

### 3. Lo que no se purga nunca

> **Los originales de las fotos, en ningún caso, por ningún plazo.**

El estudio los entrega de por vida. No son un registro que se pueda eliminar por
vencimiento de un plazo. Su conservación va en `conservacion`, que es otra cosa:
el estado del medio, no el vencimiento de un registro.

Cuando un medio se cae o se daña, se marca `danada` y se deja de asignar. **No se
borra el registro**: se marca, porque "en marzo el disco A se cayó y perdimos la
sesión X" es un hecho que hay que poder contar. Ver [08](08-RESPALDOS.md).

### 4. El acceso del titular

Si una persona pide ver qué datos hay de ella, el sistema **no tiene una pantalla
para eso**. Los datos están dispersos en contratos, sesiones, selecciones y
accesos. Es una limitación real: hoy la respuesta a un reclamo hay que armarla a
mano, consulta por consulta.

Está declarado acá en vez de escondido.

---

## Los tres roles y qué pueden ver

| Rol | Ficha de cliente | Contrato | Selección | Originales |
|---|---|---|---|---|
| `recepcion` | sí | sí | sí | según la cuenta |
| `fotografo` | sí | no | sí | según la cuenta |
| `administracion` | sí | sí | sí | según la cuenta |

### `puede_ver_archivos` es por cuenta, no por rol

El rol dice qué puede hacer **con los datos**. Este permiso dice si puede **abrir
el archivo**. Son cosas distintas:

- Una recepción que necesita ver originales para imprimirlos: sí.
- Un fotógrafo que solo sube el inventario: no necesita abrirlos.

La última cuenta de administración no se puede desactivar: sin esa regla,
desactivarla deja el estudio sin nadie que pueda administrar los paquetes ni los
laboratorios, y recuperarlo requiere tocar la base a mano.

---

## La auditoría de accesos a archivos

Cada intento de abrir un original queda escrito en `accesos_archivo`, **permitido o
denegado**, con la dirección desde la que vino.

### El denegado también se anota

El middleware anota y **después** corta. Es al revés de lo intuitivo, y a propósito:
el registro que más importa es el del intento que se denegó, porque es el que
dice que alguien quiso ver algo que no debía.

### La conexión de auditoría es aparte

`accesos_archivo` se escribe por una **conexión separada** (`auditoria` en
`config/database.php`).

La razón es una sola y es técnica: el registro tiene que **sobrevivir a la reversa
de la petición que lo denegó**. Con la misma conexión, el registro se perdería
justo en el caso que más importa. Ver
`PerteneceAlEstudio::conexionDeAuditoria()`.

### Lo que la auditoría NO cubre

La RLS protege las filas. **El archivo no está dentro de PostgreSQL**: depende de
los permisos del sistema de archivos donde vive el medio declarado en
`conservaciones`. Si ese medio está montado con permisos abiertos, la auditoría
registra el acceso pero no lo impide.

Esa es una decisión de infraestructura que hay que revisar por separado. Ver
[08](08-RESPALDOS.md).

---

## Lo que la aplicación protege por diseño

- **Aislamiento entre estudios por base de datos**, no por código. Ver
  [03](03-MULTITENANCY-Y-ROLES.md).
- **Un rechazo responde 404**, para no confirmar que un recurso existe.
- **El archivo se audita incluso si se deniega.**
- **No se purga nada sin un plazo configurado**, y no hay plazo configurado.
- **Nada se borra**: las personas se desactivan, los contratos se anulan con motivo.
- **El registro con nivel `debug` está desactivado en producción** porque puede
  incluir el cuerpo de una petición, y en un estudio eso incluye el nombre de la
  persona de la que son las fotos que se están por entregar.

---

## Lo que hay que hacer y el software no puede

| Qué | Por qué no lo hace el software |
|---|---|
| Declarar la finalidad por escrito | Es una decisión del cliente |
| Fijar los plazos con revisión legal | Mismo caso |
| Avisar a los titulares | Es una decisión del cliente, con su abogado |
| Revisar los permisos del medio de almacenamiento | Es infraestructura, no aplicación |
| Firmar el contrato con el estudio | Es un acto jurídico, no una fila |
| Respaldar los originales **fuera** del servidor | La copia es otra operación, distinta |

La última es la más urgente y la más fácil de olvidar. Ver
[08](08-RESPALDOS.md).

---

© 2026 Hefesto Studio. Software propietario.