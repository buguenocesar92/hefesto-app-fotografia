# 09 · API

Todas las rutas cuelgan del prefijo `/api/v1`. Esta es la lista completa, tal como
está en `api/routes/web.php`.

---

## Cómo se llama

**El navegador nunca llama a la API directamente.** Llama a `/api/*` en el servidor
de la interfaz, y `web/server/api/[...ruta].ts` reenvía a `/api/v1/*` arrastrando
la cookie y la cabecera `X-XSRF-TOKEN`.

| | |
|---|---|
| Autenticación | Cookie de sesión de Laravel (`HttpOnly`) |
| CSRF | Cabecera `X-XSRF-TOKEN` |
| Formato | JSON |
| Zona horaria | `America/Santiago` |

No hay ningún token en el código del navegador.

---

## Los tres códigos de respuesta que hay que conocer

| Código | Cuándo | Qué significa |
|---|---|---|
| **404** | la Policy rechazó | El recurso no existe **o no es tuyo**. No se distingue entre las dos cosas |
| **422** | `DomainException` | El dominio se negó. Viene `{"mensaje": "..."}` con texto legible |
| **500** | error de infraestructura | La aplicación se cayó de verdad |

Un 403 no existe en esta API a propósito: confirmaría que el recurso existe. En un
estudio eso importa más que en un catálogo: no se le dice a quien preguntó si esa
sesión existe en otro estudio.

---

## Autenticación

Sin sesión y sin contexto de estudio: es la lista del selector de ingreso.

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/auth/estudios` | Lista desde `vw_estudios_publicas`. Solo `slug`, `nombre`, `activo` |
| `GET` | `/api/v1/auth/entrar/{slug}` | Redirige a Authentik |
| `GET` | `/api/v1/auth/callback` | Valida el `id_token` y abre sesión |

Con sesión:

| Método | Ruta | Qué hace |
|---|---|---|
| `POST` | `/api/v1/auth/logout` | Cierra sesión |
| `POST` | `/api/v1/auth/salir` | Igual que `logout`, en español |
| `GET` | `/api/v1/auth/usuario` | La persona conectada y su rol |

`logout` y `salir` son las dos: la interfaz usa el español, y `logout` queda para
los que vienen del patrón de las otras aplicaciones.

### `GET /api/v1/salud`

La declara `bootstrap/app.php` con `health:`. Devuelve el estado del proceso.

**No se verificó que el proveedor de despliegue la use.** Si el balanceador
configurado usa otra ruta, hay que mirarlo a mano.

---

## Panel

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/panel` | Lo primero que se ve al entrar |

---

## Agenda

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/agenda` | Sesiones por fecha. Título, tipo, quién atiende |
| `GET` | `/api/v1/agenda/tipos` | Los tipos de sesión |
| `GET` | `/api/v1/agenda/fotografos` | Quién puede atender |
| `GET` | `/api/v1/sesiones/{sesion}` | El detalle, con su selección y su conservación |
| `POST` | `/api/v1/sesiones` | Agendar |
| `POST` | `/api/v1/sesiones/{sesion}/realizada` | Registrar que se tomó, con su fecha |

`registrarRealizacion` es una ruta aparte y no un `PATCH` a propósito: es **un
hecho nuevo**, con su fecha. No es editar la sesión.

### Sobre el saldo y la fecha prometida

La agenda devuelve poco a propósito: el detalle de una sesión está en
`sesiones/{id}`. Cuando el plazo de entrega no está configurado,
`fecha_prometida_entrega` viene en `null` y la pantalla lo declara. Ver
[05](05-CONFIGURACION.md).

---

## Selección de fotos

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/sesiones/{sesion}/seleccion` | Lo que se ha marcado |
| `POST` | `/api/v1/sesiones/{sesion}/seleccion` | Registrar la elección |
| `POST` | `/api/v1/sesiones/{sesion}/entregar` | Marcar entregada |

### `POST .../entregar` es la ruta con más reglas

`GestorDeEstadoDeSesion::exigirSeleccionConfirmada()` comprueba **en el momento de
escribir**, no solo al mostrar el botón:

- Sin selección `confirmada` → **422** con el motivo.
- Con la selección todavía `en_curso` → **422**. `confirmada` es el punto de no
  retorno.

La misma condición se expone como `motivo_de_no_poder_entregar` para deshabilitar
el botón sin dejarlo mudo.

---

## Clientes

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/clientes` | Lista |
| `POST` | `/api/v1/clientes` | Crear |
| `GET` | `/api/v1/clientes/{cliente}` | Ficha |
| `PUT` | `/api/v1/clientes/{cliente}` | Actualizar |
| `POST` | `/api/v1/clientes/{cliente}/desactivar` | **Desactiva**, no borra |

`destroy` es el nombre interno del método, pero **la ruta es `desactivar` y es un
`POST`**. El verbo HTTP no dice "borrar" porque la persona no se borra: puede
volver en tres años y su historial tiene que seguir ahí. Ver
[02](02-MODELO-DE-DATOS.md).

---

## Contratos

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/contratos` | Lista |
| `POST` | `/api/v1/contratos` | Crear, con sus líneas y su abono inicial |
| `GET` | `/api/v1/contratos/{contrato}` | Detalle con el saldo |
| `POST` | `/api/v1/contratos/{contrato}/anular` | Anular con motivo |
| `POST` | `/api/v1/contratos/{contrato}/entregas` | Registrar una entrega |

### `POST .../entregas` es un `POST` y no un `PATCH` a propósito

Es **un hecho nuevo**, y una pantalla que guardara dos veces sumaría dos veces.

Y una entrega parcial **nunca cierra el contrato**. `RegistradorDeEntrega`:
- sube `cantidad_entregada` de la línea;
- **rechaza** entregar más de lo pactado (422);
- **rechaza** líneas de impresión o álbum sin selección confirmada (422);
- cierra el contrato **solo** cuando todas las líneas están completas.

---

## Saldos y abonos

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/saldos` | Saldos de los contratos abiertos |
| `GET` | `/api/v1/saldos/metodos` | Métodos de abono disponibles |
| `GET` | `/api/v1/saldos/{contrato}` | El desglose de un contrato |
| `POST` | `/api/v1/saldos/{contrato}/abonos` | Registrar un abono |

### La respuesta es un desglose, no un número

Ocho cifras y dos listas. Porque "cuánto entregué", "cuánto falta" y "cuánto me
deben" son tres preguntas distintas.

`saldo_pendiente` y `saldo_a_favor` son **ambos no negativos** (`max(0, ...)`).
Nunca un número con signo: un saldo en negativo en pantalla se lee como una deuda
del estudio, que es lo contrario de lo que significa.

El cálculo es sobre `contratos.precio_total`, no sobre lo entregado. Ver
[06](06-PRIMIER-CONTRATO.md).

---

## Laboratorio

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/laboratorio` | Pedidos |
| `GET` | `/api/v1/laboratorio/directorios` | Laboratorios con los que se trabaja |
| `POST` | `/api/v1/laboratorio` | Crear un pedido |
| `GET` | `/api/v1/laboratorio/{pedido}` | Detalle |
| `POST` | `/api/v1/laboratorio/{pedido}/enviar` | Enviar |
| `POST` | `/api/v1/laboratorio/{pedido}/recibir` | Recibir |

`recibir` acepta `recibido` y **`recibido_con_fallos`**. Un laboratorio entrega con
faltantes y el sistema tiene que poder decirlo sin que el pedido parezca completo.

El contenido se **congela al enviarlo**: después hay un trabajo en curso en otro
lado.

---

## Conservación

| Método | Ruta | Qué hace | Quién |
|---|---|---|---|
| `GET` | `/api/v1/conservaciones` | Lista | los tres roles |
| `POST` | `/api/v1/conservaciones` | Declarar un medio | administración |
| `GET` | `/api/v1/conservaciones/en-riesgo` | Sesiones con originales en un medio que no está vivo | los tres roles |
| `GET` | `/api/v1/conservaciones/{conservacion}` | Historial de verificaciones | los tres roles |
| `PATCH` | `/api/v1/conservaciones/{conservacion}` | Cambiar el estado | administración |
| `POST` | `/api/v1/conservaciones/{conservacion}/verificar` | Registrar una revisión | administración |

La lista la ven las tres personas: saber en qué disco están las fotos de una
sesión es lo primero que hay que poder contestar cuando alguien pregunta por una
foto. Lo que es de administración es cambiar el **estado**, porque eso es una
afirmación sobre la custodia.

Ver [08](08-RESPALDOS.md).

---

## Archivos: todo esto va auditado

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/sesiones/{sesion}/archivos/{foto}` | La **ficha** del original |

**Lo que devuelve es la ficha, no el binario**: nombre, bytes y huella SHA-256.

No se pudo verificar cómo se montan los medios declarados en `conservaciones`, así
que la lectura real del archivo **quedó sin implementar**. Está declarado como
pendiente en vez de inventar un montaje. Ver [08](08-RESPALDOS.md).

Todo intento queda en `accesos_archivo`, **permitido o denegado**, con la dirección
desde la que vino. El middleware `archivo.auditado:descarga` anota y después corta,
por eso el registro del intento denegado es el que más importa. Ver
[07](07-SEGURIDAD-Y-LEY-19628.md).

---

## Administración

Cuelgan del middleware `administracion`, que corta para quien no administra
**antes** de llegar al controlador.

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/administracion/paquetes` | Lista de paquetes |
| `POST` | `/api/v1/administracion/paquetes` | Guardar un paquete |
| `GET` | `/api/v1/administracion/laboratorios` | Lista de laboratorios |
| `POST` | `/api/v1/administracion/laboratorios` | Guardar un laboratorio |
| `GET` | `/api/v1/administracion/cuentas` | Cuentas del estudio |

---

© 2026 Hefesto Studio. Software propietario.