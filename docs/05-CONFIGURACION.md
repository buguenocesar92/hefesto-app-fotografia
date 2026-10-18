# 05 · Configuración

Cada variable, qué hace y **por qué tantas nacen vacías**. La respuesta corta es
una sola, y está al final de este documento.

---

## La regla de `config/fotografia.php`

> Un número puesto ahí es una promesa que el software le está haciendo a un cliente
> sin que nadie la haya acordado.

De ahí se sigue la separación:

| Categoría | Qué entra | Valor por omisión |
|---|---|---|
| **Restricciones técnicas** | duración máxima de una sesión, anticipación máxima, días entre verificaciones | **Sí**, y se explica por qué |
| **Decisiones de configuración** | porcentaje de abono inicial, exigir abono, permitir abono parcial | **Sí**, son de la instalación |
| **Promesas comerciales** | plazo de entrega, plazo de laboratorio, plazo de selección, retención de datos | **Nunca.** Nacen en `null` |

Un plazo sin configurar que se ve como configurado es peor que uno que se ve
pendiente: el primero hace que el estudio crea que tiene un plazo cuando no lo
tiene.

---

## Aplicación

| Variable | Por omisión | Nota |
|---|---|---|
| `APP_NAME` | `Fotografía` | |
| `APP_ENV` | `local` | `production` en producción |
| `APP_DEBUG` | `false` | El registro con nivel `debug` puede incluir el cuerpo de una petición, y en un estudio eso incluye el nombre de la persona de la que son las fotos que se están por entregar |
| `APP_TIMEZONE` | `America/Santiago` | El plazo es por día, no por hora |
| `APP_KEY` | vacío | Generar con `php artisan key:generate`. El `entrypoint` aborta si no empieza con `base64:` |
| `LOG_LEVEL` | `info` | En producción `info` o más alto |

---

## Base de datos

| Variable | Por omisión | Nota |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | **Obligatorio.** La RLS no existe en otro motor |
| `DB_USERNAME` | `fotografia_app` | **No puede ser superusuario ni tener `BYPASSRLS`** |
| `DB_SSLMODE` | `prefer` | `require` si la base queda afuera de la instalación |

Las dos líneas que hay que leer dos veces:

> El rol de la aplicación NO puede ser superusuario ni tener BYPASSRLS: con
> cualquiera de los dos, PostgreSQL ignora las políticas. En desarrollo lo crea el
> script `deploy/postgres/inicializar-rol.sh` que el compose monta en
> `/docker-entrypoint-initdb.d/`.

Ver [03](03-MULTITENANCY-Y-ROLES.md).

---

## Sesión

| Variable | Por omisión | Nota |
|---|---|---|
| `SESSION_DRIVER` | `database` | Sobre la tabla `sesion_web` |
| `SESSION_EXPIRACION_MINUTOS` | `120` | En un estudio conviene poco: el equipo se turna en el mismo puesto, y una sesión abierta mucho rato es una sesión abierta a la ficha de la persona de la que son las fotos que se están por entregar |
| `SESSION_SECURE_COOKIE` | `false` | `true` en producción, con TLS válido |
| `SESSION_SAME_SITE` | `lax` | |

---

## Authentik

| Variable | Por omisión | Nota |
|---|---|---|
| `AUTHENTIK_ISSUER` | vacío | URL pública, **sin barra final** |
| `AUTHENTIK_CLIENT_ID` | vacío | Cliente **exclusivo** de esta aplicación |
| `AUTHENTIK_ALGORITMO` | `RS256` | |
| `AUTHENTIK_LEEWAY` | `60` | Margen de vigencia, en segundos |
| `AUTHENTIK_CACHE_METADATA` | `60` | |
| `AUTHENTIK_CACHE_JWKS` | `1440` | Las claves rotan poco; no tiene sentido pedirlas cada minuto |
| `AUTHENTIK_GRUPO_POR_ESTUDIO` | `estudio` | Prefijo. El grupo completo es `estudio-<identificador>` |
| `AUTHENTIK_EQUIPO_RECEPCION` | `Recepción de Fotografía` | |
| `AUTHENTIK_EQUIPO_FOTOGRAFO` | `Fotografía de Hefesto` | |
| `AUTHENTIK_EQUIPO_ADMINISTRACION` | `Administración de Fotografía` | |

Los tres equipos son **valores de configuración, no texto de pantalla**: pueden
llevar tilde, pero deben coincidir exactamente con Authentik. Si el equipo de
Authentik se llama distinto, el rol sale `null` y la persona entra sin rol.

---

## Agenda

| Variable | Por omisión | Por qué sí |
|---|---|---|
| `FOTOGRAFIA_DURACION_MAXIMA_MINUTOS` | `600` | Una sesión de veinte horas casi siempre es un error de tipeo |
| `FOTOGRAFIA_ANTICIPACION_MAXIMA_DIAS` | `540` | Agendar para dentro de tres años es un error de tipeo |
| `FOTOGRAFIA_ANTICIPACION_MINIMA_DIAS` | `0` | Cero significa que se acepta agendar para hoy, que es lo normal en un estudio chico que tiene huecos |

**La duración de una sesión NO sale de acá.** Sale de la línea del contrato que
la promete: un paquete de "sesión de dos horas" se traduce en una línea de dos
horas y de ahí a la agenda.

---

## Paquetes y fecha prometida

| Variable | Por omisión |
|---|---|
| `FOTOGRAFIA_DIAS_PROMESA_ENTREGA` | **Vacío** |
| `FOTOGRAFIA_DIAS_PROMESA_LABORATORIO` | **Vacío** |
| `FOTOGRAFIA_FORMATOS_IMPRESION` | **Vacío** |

Las tres son promesas comerciales o acuerdos con terceros. Un paquete de bautismo
y uno de portraits de empresa no tienen el mismo plazo, y un estudio que promete
rápido cobra distinto. Poner un número sería prometerle a una persona una fecha
que nadie acordó.

Los formatos de impresión están vacíos porque **cada estudio trabaja con los que
su laboratorio ofrece**. Una lista inventada produce pedidos que el laboratorio no
puede hacer.

Un pedido a laboratorio puede además llevar el plazo pactado en esa fecha, en el
propio pedido. El valor global es solo el punto de partida.

---

## Abonos

| Variable | Por omisión | Nota |
|---|---|---|
| `FOTOGRAFIA_PORCENTAJE_ABONO_INICIAL` | `50` | |
| `FOTOGRAFIA_EXIGIR_ABONO_INICIAL` | `true` | Con `true`, un contrato sin abono queda en `borrador` y no ocupa agenda |
| `FOTOGRAFIA_PERMITIR_ABONO_PARCIAL` | `true` | En la práctica siempre se admite: la caja manda |

El cincuenta por ciento al contratar y el resto al entregar es la forma en que
se describió el ciclo de caja de este rubro, así que es el valor por omisión. No
es un precio ni un plazo legal: es la política de la instalación, y se cambia acá
cuando el estudio cambie la suya.

`porcentaje_abono_inicial` no es una comisión ni un porcentaje de comisión: es
solo la parte del contrato que se cobra al firmar.

**El saldo nunca se deduce de acá.** Lo calcula `CalculadoraDeSaldo`, que devuelve
el desglose. Ver [02](02-MODELO-DE-DATOS.md).

---

## Selección

| Variable | Por omisión |
|---|---|
| `FOTOGRAFIA_DIAS_PARA_SELECCION` | **Vacío** |
| `FOTOGRAFIA_EXCESO_SELECCION_SIN_PAGO` | **Vacío** |

Cuántos días se espera para que la persona elija depende del tipo de sesión, y no
se pudo verificar un número para todo el rubro. Con `null` el sistema **no avisa a
nadie por sí solo** y la sesión queda visible en la lista de pendientes con su
fecha.

Con `exceso_seleccion_sin_pago` en `null` no se bloquea y no se avisa: qué pasa
con las fotos de más es decisión del estudio, no del software.

---

## Conservación de los archivos

| Variable | Por omisión | Por qué |
|---|---|---|
| `FOTOGRAFIA_DIAS_ENTRE_VERIFICACIONES` | `30` | Es una decisión operativa, no una regla legal. Un mes es la unidad con la que un técnico revisa un disco |
| `FOTOGRAFIA_DIAS_MAX_SIN_VERIFICAR` | **Vacío** | Un número inventado produce avisos falsos que entrenan al equipo a ignorar el sistema |

Con `dias_max_sin_verificar` en `null` el sistema no avisa por sí solo, y la
pantalla muestra la fecha de la última verificación. Es preferible a un aviso
equivocado todas las semanas.

---

## Conservación de datos: la decisión es del cliente, no del software

| Variable | Por omisión |
|---|---|
| `FOTOGRAFIA_RETENCION_ACCESOS_ARCHIVO_DIAS` | **Vacío** |
| `FOTOGRAFIA_AVISAR_ANTES_DE_PURGAR` | `false` |

La Ley 19.628 obliga a que la conservación de datos personales tenga una finalidad
declarada, pero **no se pudo verificar** en el entorno donde se escribió esta
aplicación el plazo que corresponde a la bitácora de accesos a los archivos
originales.

Vacía significa: **no hay plazo configurado. La aplicación NO purga nada por su
cuenta.**

Y lo que importa más:

> Nótese lo que **no** se purga nunca, en ningún caso: los originales de las fotos.
> El estudio los entrega de por vida y no son un registro que se pueda eliminar
> por un plazo.

Borrar originales porque pasó un número inventado sería la peor pérdida posible
en este rubro. Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

Se completa **solo** cuando el estudio cliente, con su revisión legal, tenga un
plazo escrito. El valor va en días.

---

## Las variables que sí tienen valor por omisión

Para que quede claro que el `null` no es una dejadez: hay diez variables con
valor por omisión y cada una tiene su razón escrita al lado en el propio archivo
de configuración.

Las que parecen más discutibles tienen estas razones:

| Variable | Razón |
|---|---|
| Duración máxima de sesión | Límite técnico de la agenda |
| Anticipación máxima y mínima | Límite del selector de fechas |
| Porcentaje de abono inicial | Política descrita del ciclo de caja |
| Exigir abono inicial | Decisión de configuración |
| Permitir abono parcial | La caja manda |
| Días entre verificaciones | Unidad operativa del técnico de medios |

Y las seis que nacen vacías a propósito están listadas más arriba. La diferencia
entre unas y otras es la diferencia entre lo que el software sabe y lo que el
estudio tiene que decidir.

---

© 2026 Hefesto Studio. Software propietario.