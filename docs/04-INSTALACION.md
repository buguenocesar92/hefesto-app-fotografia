# 04 · Instalación

De cero a funcionando, y **qué comprobar en el camino**. Este documento no puede
decir "probado" en ninguna parte: la aplicación nunca se ejecutó. Ver
[10](10-PRUEBAS-Y-ESTADO.md).

---

## Requisitos

| Componente | Versión declarada | Estado |
|---|---|---|
| PHP | 8.4 | copiada de las otras aplicaciones, **sin verificar** |
| Laravel | ^13.37 | **sin verificar** contra Packagist |
| Node | 24 | **sin verificar** contra el registro de imágenes |
| Nuxt | 4.6.2 | **sin verificar** |
| PostgreSQL | 18.6 | **sin verificar** |
| Redis | 8.8.3 | **sin verificar** |
| Caddy | 2.11.7 | **sin verificar** |
| Authentik | 2025.10 | **sin verificar** |

Las versiones se copiaron del material de las demás aplicaciones de Hefesto
Studio para que el conjunto sea coherente. **Ninguna se comprobó contra un
registro.** Si alguna no existe o cambió de etiqueta, el primer `docker compose
build` lo va a decir, y ahí se corrige el número. No se puede decir acá cuál es el
número correcto: no se consultó ningún registro.

PostgreSQL no es negociable: la Row Level Security no existe en otro motor y el
aislamiento entre estudios depende de ella.

---

## Desarrollo

```bash
cd deploy
cp .env.example .env          # si existe; ver más abajo
docker compose -f docker-compose.dev.yml up -d
```

### Qué publica el compose de desarrollo

```
  postgres   127.0.0.1:5432
  redis      127.0.0.1:6379
  api        127.0.0.1:8000
  web        127.0.0.1:3000
  caddy      127.0.0.1:8080
```

Todos en `127.0.0.1`, o sea que solo desde la propia máquina. En desarrollo se
publican a propósito: sin eso no hay recarga en caliente ni se puede mirar la
base con un cliente.

### El orden de arranque

1. **PostgreSQL** levanta y ejecuta `/docker-entrypoint-initdb.d/inicializar-rol.sh`,
   que crea `fotografia_app` con sus atributos y le hace `ALTER DATABASE ... OWNER`.
   Ese script **solo corre la primera vez**, cuando el volumen de datos está
   vacío. Si el volumen ya existe, hay que correrlo a mano.
2. **Redis** levanta.
3. **La API** corre `deploy/api/docker/entrypoint.sh`, que valida el `.env` y la
   `APP_KEY`, corre las migraciones y después corre el verificador de
   aislamiento. Si ese verificador falla, **el contenedor no arranca**.
4. **La cola** corre el worker de Laravel con `stopasgroup` y `killasgroup`.
5. **La interfaz** compila y sirve.
6. **Caddy** enruta `/api/*` a la API y el resto a la interfaz.

### Por qué las migraciones corren al arrancar

En un estudio hay una sola base y el despliegue lo hace el operador o el estudio.
Correrlas en el `entrypoint` evita el paso manual que siempre se olvida, y sobre
todo evita que alguien las olvide y descubra la tabla faltante cuando una persona
esté esperando su turno de fotos. El bloqueo de PostgreSQL hace que dos réplicas
arrancando a la vez no las corran en paralelo.

---

## Producción

```bash
cd deploy
docker compose -f docker-compose.prod.yml up -d
```

**Lo único que publica puertos es Caddy**: `80`, `443` y `443/udp`. PostgreSQL,
Redis, la API y la cola **no tienen `ports:`**, quedan en la red interna de
Compose. La razón es que la Row Level Security protege las filas, pero un puerto
de PostgreSQL abierto al mundo es una puerta que no depende de las políticas.

La API expone el puerto 8000 **internamente**, para nginx. Ese 8000 no está en el
host.

---

## Pasos manuales que hay que hacer una vez

### 1. La clave de la aplicación

```bash
docker compose -f docker-compose.prod.yml exec api php artisan key:generate
```

El `entrypoint.sh` aborta si `APP_KEY` no empieza con `base64:`. Es deliberado:
un contenedor sano que responde mal es peor que uno que no responde, porque el
balanceador le manda tráfico igual.

### 2. El primer estudio

```bash
docker compose -f docker-compose.prod.yml exec api \
    php artisan fotografia:crear-estudio
```

Da de alta un estudio con su primer administrador. Es la **única excepción
controlada** al aislamiento: sin sesión no hay contexto de estudio, y sin
contexto `app_tenant_id()` lanzaría excepción. El comando usa la misma función,
con el mismo alcance transaccional, y con el estudio que está creando.

### 3. La URL de retorno en Authentik

Tiene que coincidir **exactamente** con `AUTHENTIK_REDIRECT_URI`. Si no
coincide, Authentik rechaza el canje y el error aparece como un fallo de
validación de token, que es bastante menos útil que "la dirección de retorno no
coincide".

---

## Comprobaciones antes de recibir a la primera sesión

En este orden. Las dos primeras son las que más caro salen de saltarse.

### 1. El aislamiento está activo

```bash
docker compose -f docker-compose.prod.yml exec api \
    php artisan fotografia:verificar-aislamiento
```

Debe salir `AISLAMIENTO: OK`. Si no, **no siga**: el Entry aborta por esto mismo,
pero conviene correrlo a mano una vez para ver el detalle de las seis
comprobaciones.

### 2. El rol no es superusuario

```sql
SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = 'fotografia_app';
```

`rolsuper` y `rolbypassrls` tienen que estar en `false`. Si `rolsuper` está en
`true`, el aislamiento no existe aunque todo lo demás esté bien.

### 3. La vista pública expone tres columnas

```sql
SELECT column_name FROM information_schema.columns
WHERE table_name = 'vw_estudios_publicas';
```

Deben salir `slug`, `nombre` y `activo`. Una cuarta columna es un fuga de datos
antes de tiempo.

### 4. La aplicación responde

```bash
curl -s https://<dominio>/api/v1/salud
```

Esa ruta la declara `bootstrap/app.php` con `health:`. **No se verificó que el
proveedor de despliegue la use**, así que conviene mirarla a mano una vez antes
de confiarle el balanceo del tráfico.

### 5. La cola está viva

```bash
docker compose -f docker-compose.prod.yml logs --tail=50 cola
```

El worker tiene que estar conectado. Una cola caída no se nota hasta que alguien
registra una selección y la pantalla no cambia.

---

## Problemas frecuentes

| Síntoma | Causa habitual |
|---|---|
| El contenedor de la API no arranca | Falta `.env` o `APP_KEY` no empieza con `base64:` |
| `insufficient_privilege` en el registro | Una ruta sin contexto de estudio, o el worker corre sin transacción |
| `permission denied for schema public` | El rol no es el dueño de la base: falta el `ALTER DATABASE` |
| No aparece el estudio en el selector | La vista pública está vacía, o el estudio tiene `activo = false` |
| El login vuelve a la pantalla de ingreso | `AUTHENTIK_REDIRECT_URI` no coincide, o el equipo de Authentik no está bien |
| Falla al copiar las fotos | La ruta devuelve la ficha, no el binario. Ver [08](08-RESPALDOS.md) |

---

© 2026 Hefesto Studio. Software propietario.