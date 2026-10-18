# 03 · Multitenancy y roles

El aislamiento entre estudios lo impone PostgreSQL, no la aplicación. Y un
permiso que se rechaza responde **404**, no 403.

---

## El aislamiento, en cuatro piezas

### 1. La columna

Toda tabla de negocio tiene `tenant_id uuid` con clave foránea a `estudios`.
La migración `2026_10_18_000012` verifica, tabla por tabla, que la columna exista
**antes** de proteger nada.

Sin esa comprobación, un error de tipeo en la lista dejaría una tabla de datos de
clientes sin protección y la migración pasaría igual. Es el modo de falla más
silencioso que existe, y por eso el verificador de aislamiento la comprueba.

### 2. La política

```sql
CREATE POLICY politica_aislamiento_estudio ON <tabla>
    FOR ALL
    TO PUBLIC
    USING (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

`WITH CHECK` no es opcional. Sin él se podría insertar una fila declarando otro
estudio: la lectura la ocultaría y nadie la vería nunca, que es peor que ver un
error.

### 3. `FORCE ROW LEVEL SECURITY`

```sql
ALTER TABLE <tabla> ENABLE ROW LEVEL SECURITY;
ALTER TABLE <tabla> FORCE ROW LEVEL SECURITY;
```

Sin `FORCE`, las políticas no aplican al dueño de la tabla. Y el rol de la
aplicación **es** el dueño (por eso el script le hace `ALTER DATABASE ... OWNER`,
para que las migraciones corran). Sin `FORCE`, el aislamiento sería decorativo
justo donde se prueba.

### 4. `app_tenant_id()` falla cerrado

La función lee `current_setting('app.tenant_id', true)` y, si viene vacío,
**lanza excepción** con el código `insufficient_privilege`.

Podría devolver `NULL` y filtrar todo en silencio. Se eligió que falle ruidoso: un
registro vacío en pantalla es un error que se descubre a mitad de mes, y una
excepción en el log dice exactamente qué operación se saltó el middleware.

---

## El contexto se fija con alcance de transacción

`ContextoEstudio::ejecutarAislado()` usa `set_config('app.tenant_id', ..., true)`.
El tercer parámetro es lo que la hace de alcance **transaccional**.

Un `SET` de sesión sobrevive al commit, y el worker de colas reutiliza la
conexión: con alcance de sesión, una tarea leería datos de otro estudio. Por eso
el supervisor corre el worker con `stopasgroup` y `killasgroup`, para que muera y
vuelva.

`ResolverEstudio` envuelve la petición autenticada en esa transacción. Corre
**antes** del middleware `auth`, así que resuelve el usuario por su cuenta con el
guard `web`, que ya lee la sesión.

Peticiones sin sesión (ingreso, salud) pasan sin transacción. Si esas rutas
tocaran una tabla protegida, `app_tenant_id()` lanzaría excepción y la petición
fallaría: **el aislamiento falla cerrado, nunca abierto**.

---

## Las dieciséis tablas protegidas

```
  usuarios            clientes             paquetes
  contratos           lineas_contrato      sesiones
  fotos               selecciones           seleccion_detalles
  laboratorios         pedidos_laboratorio  pedido_laboratorio_items
  abonos              conservaciones       conservacion_verificaciones
  accesos_archivo
```

### Y las dos que no llevan RLS, y por qué

**`estudios` es el tenant.** Se consulta antes de que exista sesión: es la lista
del selector de ingreso. No puede llevar RLS porque la política compararía
`tenant_id` contra un contexto que todavía no existe.

**`vw_estudios_publicas` es la salida.** Es una vista que expone exactamente tres
columnas, y **se usa de verdad**:

- `AuthController::estudios()` — la lista del selector
- `AuthController::entrar()` — validar el `slug` antes de redirigir
- `ProveedorOidcAuthentik::usuarioDesdeClaims()` — resolver el estudio del claim

Las tres columnas son `slug`, `nombre` y `activo`. Ni una más. La vista no expone
`id`, así que la lectura sin sesión no puede construir una consulta con contexto,
aunque quisiera.

En `apps/notaria`, que es la referencia de este patrón, la vista se consulta en el
mismo lugar. El punto de escribirla sin usarla es que el día que se agregue una
columna, nadie la revisa porque no está en ninguna ruta.

---

## Los dos roles de base de datos

Son dos, y la razón de que sean dos es la más importante de este documento.

### El rol de arranque

Lo crea la imagen de PostgreSQL con `POSTGRES_USER`. Es superusuario.

**La aplicación nunca lo usa.** Existe solo para el arranque, para el script de
inicialización y para las tareas manuales de un operador.

### El rol de la aplicación

`fotografia_app`, creado por `deploy/postgres/inicializar-rol.sh`, que el compose
monta en `/docker-entrypoint-initdb.d/`. Se declara con los atributos
**explícitos**:

```sql
CREATE ROLE fotografia_app WITH
    LOGIN PASSWORD '...'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
```

Y después:

```sql
ALTER DATABASE fotografia OWNER TO fotografia_app;
```

El `ALTER DATABASE` es lo que permite que las migraciones corran con el rol de la
aplicación, que es lo que hace que `FORCE ROW LEVEL SECURITY` sea una prueba
real y no decorativa.

### Por qué importa, y por qué el compose lo explica

**Un superusuario siempre saltea la Row Level Security, aunque la tabla tenga
`FORCE`.** No hay excepción a esto: `FORCE` sujeta al dueño, no al superusuario.

Eso significa que una instalación que conecta la aplicación como superusuario
tiene el aislamiento **encendido en el papel y apagado en la práctica**. Todas las
políticas están creadas, todas las comprobaciones de estructura pasan, y el
aislamiento no existe. Es la forma más cómoda de tener un sistema que parece
correcto y que no lo está.

Por eso `docker-compose.prod.yml` lleva un comentario largo explicando los dos
roles, y por eso el verificador de aislamiento comprueba que el rol **no** sea
superusuario y **no** tenga `BYPASSRLS`.

---

## El comando que hay que correr antes de recibir a la primera sesión

```bash
php artisan fotografia:verificar-aislamiento
```

Hace seis comprobaciones, desde el servidor y sin pasar por la aplicación:

1. El rol con el que conecta **no es superusuario**.
2. El rol **no tiene** `BYPASSRLS`.
3. Las dieciséis tablas tienen política con `USING` **y** `WITH CHECK`.
4. La vista `vw_estudios_publicas` expone **exactamente tres columnas**.
5. Una lectura **sin** contexto de estudio **falla**.
6. **No** se puede escribir una fila declarando el estudio de otro.

Las comprobaciones 1, 2 y 5 son las que no tiene la referencia que se siguió.
Ver la lista de errores factuales en [10](10-PRUEBAS-Y-ESTADO.md).

El `entrypoint.sh` corre este comando al arrancar y **aborta el contenedor** si
falla. Un contenedor sano que responde mal es peor que uno que no responde,
porque el balanceador le manda tráfico igual.

---

## Los tres roles de aplicación

| Rol | Qué hace |
|---|---|
| `recepcion` | Agenda clientes, agenda sesiones, cobra, registra la selección |
| `fotografo` | Toma sesiones, sube el inventario de fotos |
| `administracion` | Lo anterior más paquetes, laboratorios, conservaciones y cuentas |

El rol sale del **equipo de Authentik** al que pertenece la persona. El grupo
completo es `estudio-<identificador>`, y el nombre de los tres equipos se
configura por instalación porque son valores de configuración, no texto de
pantalla: pueden llevar tilde.

`AUTHENTIK_GRUPO_POR_ESTUDIO=estudio` es el prefijo; los tres equipos completos son
los declarados en `.env`.

### El permiso que no es del rol

`puede_ver_archivos` es **por cuenta**. El rol dice qué puede hacer con los datos;
este permiso dice si puede abrir el archivo. Una recepción que necesita ver
originales la tiene; un fotógrafo que no, no.

La **última cuenta de administración no se puede desactivar**. Sin esa regla,
desactivar la última cuenta deja el estudio sin nadie que pueda administrar los
paquetes ni los laboratorios, y recuperarlo requiere tocar la base a mano.

---

## Cómo se decide el permiso: dos capas

```
 1. auth                ¿quién es?
 2. archivo.auditado    ¿se está tocando un archivo personal?
 3. administracion      ¿es una tarea de administración?
 4. Policy              ¿este recurso es de este estudio y este rol puede?
```

Son capas distintas y el orden importa:

- El middleware `administracion` es la **puerta**: corta antes de llegar al
  controlador, para quien no administra.
- La Policy es la **regla**: decide sobre un recurso concreto.

Y la comparación en las Policies usa un solo método,
`PerteneceAlEstudio::esDelMismoEstudio()`, que **recontar**: si el recurso no
tiene `tenant_id`, NO es del mismo estudio. Un modelo mal escrito no produce un
acceso abierto: produce un rechazo.

### Por qué un rechazo es 404

Un 403 confirma que el recurso existe. En un estudio eso importa más que en un
catálogo: no se le dice a quien preguntó si esa sesión, esa selección o ese
archivo existen en otro estudio. Un 404 no distingue entre "no existe" y "no es
tuyo", y esa indistinción es exactamente lo que hay que mantener.

---

## Lo que el aislamiento NO protege

- **Los archivos.** `accesos_archivo` los audita, pero el binario vive en el medio
  declarado en `conservaciones`, y ese medio no está dentro de PostgreSQL. El RLS
  protege las filas; el archivo depende de los permisos del sistema de archivos.
  Ver [08](08-RESPALDOS.md).
- **Una conexión mal configurada.** Si la aplicación conecta como superusuario, no
  hay aislamiento. Por eso la comprobación 1.
- **Un worker de colas mal configurado.** Si el contexto se fija con alcance de
  sesión en vez de transacción, la conexión reutilizada lee datos de otro estudio.
  Ver el apartado de arriba.

---

© 2026 Hefesto Studio. Software propietario.