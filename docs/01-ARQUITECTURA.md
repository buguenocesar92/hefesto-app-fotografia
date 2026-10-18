# 01 · Arquitectura

Tres capas y una regla: **el navegador nunca habla con la API**. Todo lo que
cruza el límite pasa por el servidor de la interfaz.

---

## El dibujo completo

```
   Persona en el navegador
            |
            |  cookies de sesión, nunca un token en el código
            v
   +-------------------------+
   |  Caddy (deploy/caddy)   |   publica 80 y 443, nada más
   +-------------------------+
       |            |
       |            |
       v            v
  /             /api/*
  |             |
  v             v
+----------+  +---------+    reenvía la petición con la cookie
| Nuxt 4   |  |  BFF    |------------------------------+
| web/     |  | server/ |                              |
+----------+  +---------+                              |
       |                                                |
       | rutas de página                                 v
       |                                     +---------------------+
       +------------------------------------>| API Laravel 13       |
                                             | api/                 |
                                             +---------------------+
                                                  |         |
                                    set_config()  |         |  sesión, caché
                                    con alcance   v         v
                                    de transacción +---------+
                                                       |
                                            +--------------------+
                                            | PostgreSQL        |
                                            | RLS sobre tenant_id|
                                            +--------------------+
```

---

## Las tres capas

### 1. La interfaz (`web/`)

Nuxt 4 con render en el servidor. Nueve pantallas más el ingreso. No hay estado
de negocio en el navegador: el navegador pide y muestra.

### 2. El BFF (`web/server/`)

`server/api/[...ruta].ts` reenvía **todo** lo que llega a `/api/*` al prefijo
`/api/v1` de la API, arrastrando la cookie y la cabecera `X-XSRF-TOKEN`.

**Por qué un proxy y no un archivo por ruta.** La API tiene más de treinta rutas y
van a crecer. Con un archivo por ruta, cada endpoint nuevo obliga a escribir un
archivo en el servidor de Nuxt que solo repite la misma llamada: es la
oportunidad de que uno se quede sin escribir y la pantalla falle por algo que no
aparece en ninguna búsqueda. Con el proxy, la interfaz llama a la API por la
misma ruta que documenta [09](09-API.md) y no hay una segunda lista que mantener.

**Lo que el proxy no hace, a propósito:** no reescribe rutas, no normaliza
cuerpos, no transforma códigos de estado y no corrige errores. Un 404 vuelve como
404. Un 422 con el mensaje del dominio vuelve con ese mensaje.

### 3. La API (`api/`)

Laravel 13 sobre PostgreSQL. Aquí están las reglas del negocio: qué es una
entrega parcial, cuándo una selección está confirmada, qué es el saldo.

---

## Por qué la interfaz está en el medio y no es un detalle

Si el navegador llamara a la API directamente, haría falta un token en el
código de la página. Ese token viaja, se puede leer y termina en el registro del
navegador. Y el `SESSION_DRIVER=database` con cookie de sesión evita otra cosa:
el token no existe.

El precio de esta decisión es que hay un proceso más en el camino. Es el mismo
precio que paga cualquier aplicación con render en el servidor, y se paga a
change con una cookie en vez de con un incidente.

---

## Cómo entra la sesión

OIDC contra Authentik, con el patrón de autorización por código:

1. La pantalla de ingreso pide la lista de estudios: `GET /api/v1/auth/estudios`.
   Esa ruta corre **sin sesión** y lee la vista `vw_estudios_publicas`, que
   expone solo `slug`, `nombre` y `activo`. Ver [03](03-MULTITENANCY-Y-ROLES.md).
2. La persona elige uno y se redirige a `GET /api/v1/auth/entrar/{slug}`.
3. Authentik autentica y vuelve a `GET /api/v1/auth/callback`.
4. La API valida el `id_token` (firma, emisor, audiencia, vigencia, margen de
   60 segundos) y resuelve el equipo de Authentik a uno de los tres roles de
   aplicación: `recepcion`, `fotografo` o `administracion`.
5. Queda abierta una sesión de Laravel con cookie `HttpOnly`.

El cliente OIDC es **exclusivo de esta aplicación**: así se le puede revocar el
acceso a Fotografía sin tocar las demás aplicaciones de Hefesto.

---

## El orden de los controles en una petición

Este orden importa y es el que hay que entender antes de tocar permisos:

```
 1. auth                confirma la sesión
 2. archivo.auditado    anota el intento de abrir un original
 3. administracion      corta las rutas de administración
 4. Policy              permiso fino, con 404 si rechaza
```

Son cuatro capas y cada una responde a una pregunta distinta: ¿quién es?, ¿se
está tocando un archivo personal?, ¿es una tarea de administración?, ¿este
recurso es de este estudio y este rol puede tocarlo?

### Dos decisiones que salen de ahí

**Un rechazo es 404, no 403.** En `bootstrap/app.php`, una
`AuthorizationException` en `/api/v1/*` se traduce a `404 No encontrado`. Un 403
confirmaría que el recurso existe. En un estudio eso importa más que en un
catálogo: no se le dice a quien preguntó si esa sesión, esa selección o ese
archivo existen en otro estudio.

**Un error del dominio es 422, no 500.** Una sesión sin selección confirmada, un
pedido recibido antes de enviarse o una entrega que supera lo pactado lanzan
`DomainException` y responden 422 con un mensaje legible. Un 500 haría pensar que
la aplicación se cayó, cuando lo que pasó es que el sistema hizo lo que debía.

Solo `DomainException`. Un `RuntimeException` sigue siendo 500, porque un error de
infraestructura —que no haya contexto de estudio, por ejemplo— tiene que verse
como lo que es y no vestirse de error de negocio.

---

## Dónde vive cada regla

```
  Regla                                    Dónde está
  ---------------------------------------  ------------------------------
  Una sesión no se entrega sin selección   Sesiones/GestorDeEstadoDeSesion
  Una entrega parcial no cierra contrato   Contratos/RegistradorDeEntrega
  El saldo es un desglose, no un número     Cobranza/ServicioDeSaldo
  El cálculo del saldo es puro             Cobranza/CalculadoraDeSaldo
  El aislamiento lo impone la base         migración 000012 + app_tenant_id()
  El plazo sin configurar se declara       config/fotografia.php con null
  Quién puede tocar qué                    Policies/, tabla por tabla
  Dónde están los originales               tabla conservaciones
```

La regla que atraviesa todo: **una regla de negocio vive en un servicio con su
propio nombre, no en un controlador**. Un controlador traduce peticiones a
llamadas. Si una regla queda en un controlador, la siguiente pantalla que necesite
esa regla la va a reimplementar, y las dos van a diferir en algún detalle.

---

## Lo que la aplicación NO hace

- **No sube archivos.** `FotoArchivoController` devuelve la ficha del original
  (nombre, bytes, huella SHA-256), no el binario. No se pudo verificar cómo se
  montan los medios declarados en `conservaciones`, así que la lectura real quedó
  sin implementar y declarada como pendiente. Ver [08](08-RESPALDOS.md).
- **No manda correos.** No hay nada de notificación automática: un plazo sin
  configurar que dispara un correo es una decisión comercial que el estudio tiene
  que tomar, no el software. Ver [05](05-CONFIGURACION.md).
- **No borra.** Ver [02](02-MODELO-DE-DATOS.md).
- **No inventa precios ni plazos.** Ver [05](05-CONFIGURACION.md).

---

© 2026 Hefesto Studio. Software propietario.