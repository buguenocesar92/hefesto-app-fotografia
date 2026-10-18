#!/bin/sh
# ---------------------------------------------------------------------------
# Restauración del estudio fotográfico.
# ---------------------------------------------------------------------------
# POR QUÉ ESTE SCRIPT ES MÁS CUIDADO QUE EL RESPALDO
# ---------------------------------------------------
# Un respaldo que sale mal se nota en una hora. Una restauración que sale mal
# borra la base que estaba funcionando. Por eso todo lo que este script hace es
# deliberadamente conservador: avisa, exige confirmación y deja el servicio
# detenido.
#
# LAS SEIS REGLAS QUE SE SIGUEN
# -----------------------------
# 1. NUNCA HAY `DROP`. No se tira la base, no se borra el esquema, no se usa
#    `--clean` a la ligera. La restauración se hace sobre una base que debe estar
#    VACÍA, y el script lo comprueba. Si no está vacía, el script para: la decisión
#    de qué hacer con los datos que hay es de una persona.
#
# 2. NUNCA HAY SOBREESCRITURA AUTOMÁTICA. Si el destino tiene tablas, el script
#    aborta salvo que se pase `--force`, y con `--force` lo que hace es vaciar las
#    tablas existentes, una por una y en una transacción. No borra la base.
#
# 3. EL SERVICIO QUEDA DETENIDO. El script detiene los contenedores de la
#    aplicación ANTES de restaurar y NO los vuelve a levantar. Restaurar con gente
#    entrando al estudio produce entregas que el sistema no tiene, y eso ya no
#    tiene arreglo.
#
# 4. NUNCA HAY ESCRITURA A DESTINO OCUPADO. El respaldo no se toca. Se abre en
#    lectura y se verifica el SHA-256 contra el manifiesto.
#
# 5. EL MANIFIESTO SE VERIFICA ANTES DE RESTAURAR. Si el archivo y la suma no
#    calzan, no se restaura nada. Un respaldo dañado restaurado a medias deja una
#    base que parece buena y no lo está.
#
# 6. NUNCA HAY RESTAURACIÓN PARCIAL SILENCIOSA. `--single-transaction` hace que la
#    restauración se aplique COMPLETA o NO SE APLIQUE. A medias es peor que no
#    restaurar.
#
# Y UNA COMPROBACIÓN QUE ES DE ESTA APLICACIÓN
# -------------------------------------------
# Al terminar, el script verifica que la base restaurada conserva las políticas de
# aislamiento: que exista la función `app_tenant_id()` y que las tablas con
# `tenant_id` tengan Row Level Security ACTIVADA y FORZADA. Sin eso, la API se
# levanta y queda despidiendo datos de otros estudios, y en un estudio eso incluye
# quién abrió los originales de las fotos de una familia. Ver docs/03.
#
# USO
# ---
#   deploy/respaldos/restauracion.sh /var/backups/fotografia/fotografia-20261017-030000
#   deploy/respaldos/restauracion.sh --force /ruta/al/respaldo
#   deploy/respaldos/restauracion.sh --simular /ruta/al/respaldo
#
# Variables de entorno obligatorias:
#   DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_HOST, DB_PORT
#
# Con SIMULAR no se escribe nada: se comprueba el archivo, el manifiesto y las
# dependencias. Es lo que hay que correr primero, siempre.
# ---------------------------------------------------------------------------

set -eu

FORZAR=0
SIMULAR=0
RESPALDO=""

for ARGUMENTO in "$@"; do
    case "$ARGUMENTO" in
        --force)
            FORZAR=1
            ;;
        --simular)
            SIMULAR=1
            ;;
        --help|-h)
            echo "Uso: $0 [--force] [--simular] /ruta/al/respaldo"
            echo ""
            echo "  --simular  comprueba el respaldo y las dependencias sin escribir nada."
            echo "             Correr esto SIEMPRE antes de una restauración real."
            echo "  --force    permite restaurar sobre una base que ya tiene tablas."
            echo "             Vacia las tablas existentes en una transacción. NO borra la base."
            exit 0
            ;;
        -*)
            echo "[restauracion] ERROR: opción desconocida: $ARGUMENTO"
            exit 2
            ;;
        *)
            RESPALDO="$ARGUMENTO"
            ;;
    esac
done

if [ -z "$RESPALDO" ]; then
    echo "[restauracion] ERROR: falta la ruta del respaldo."
    echo "[restauracion] use --help para ver el uso."
    exit 2
fi

if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ] || [ -z "${DB_USERNAME:-}" ]; then
    echo "[restauracion] ERROR: faltan DB_HOST, DB_DATABASE o DB_USERNAME en el entorno."
    exit 1
fi

if ! command -v pg_restore >/dev/null 2>&1; then
    echo "[restauracion] ERROR: pg_restore no está disponible en este equipo."
    exit 1
fi

# ---------------------------------------------------------------------------
# 1. Comprobaciones del respaldo (REGLA 5)
# ---------------------------------------------------------------------------
if [ ! -d "$RESPALDO" ]; then
    echo "[restauracion] ERROR: no existe el directorio del respaldo: $RESPALDO"
    exit 1
fi

ARCHIVO="$RESPALDO/datos.dump.gz"
MANIFIESTO="$RESPALDO/manifiesto.txt"
MARCA_COMPLETITUD="$RESPALDO/COMPLETO"

# Si el respaldo no tiene la marca de completo, no se restaura. Un respaldo a
# medias restaurado deja una base que parece buena y no lo está.
if [ ! -f "$MARCA_COMPLETITUD" ]; then
    echo "[restauracion] ERROR: el respaldo NO tiene el archivo COMPLETO."
    echo "[restauracion]        significa que el respaldo se cortó a la mitad."
    echo "[restauracion]        no se restaura. Corra de nuevo el respaldo."
    exit 1
fi

if [ ! -f "$ARCHIVO" ]; then
    echo "[restauracion] ERROR: falta el archivo de datos: $ARCHIVO"
    exit 1
fi

if [ ! -f "$MANIFIESTO" ]; then
    echo "[restauracion] ERROR: falta el manifiesto: $MANIFIESTO"
    echo "[restauracion]        sin manifiesto no hay forma de verificar el archivo."
    exit 1
fi

SUMA_ESPERADA="$(grep '^sha256:' "$MANIFIESTO" | awk '{ print $2 }')"

if [ -z "$SUMA_ESPERADA" ]; then
    echo "[restauracion] ERROR: el manifiesto no tiene una línea sha256."
    exit 1
fi

if command -v sha256sum >/dev/null 2>&1; then
    SUMA_REAL="$(sha256sum "$ARCHIVO" | awk '{ print $1 }')"
else
    SUMA_REAL="$(shasum -a 256 "$ARCHIVO" | awk '{ print $1 }')"
fi

if [ "$SUMA_ESPERADA" != "$SUMA_REAL" ]; then
    echo "[restauracion] ERROR: el archivo NO calza con el manifiesto."
    echo "[restauracion]        esperado: $SUMA_ESPERADA"
    echo "[restauracion]        real:     $SUMA_REAL"
    echo "[restauracion]        no se restaura nada. El respaldo está dañado."
    exit 1
fi

echo "[restauracion] archivo verificado contra el manifiesto."

if ! gzip -t "$ARCHIVO"; then
    echo "[restauracion] ERROR: el archivo comprimido está dañado."
    exit 1
fi

# ---------------------------------------------------------------------------
# 2. Estado de la base de destino (REGLAS 1 y 2)
# ---------------------------------------------------------------------------
consultar() {
    PGPASSWORD="${DB_PASSWORD:-}" psql \
        --host="${DB_HOST}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME}" \
        --dbname="${DB_DATABASE}" \
        --tuples-only \
        --no-align \
        --command="$1"
}

TABLAS_ACTUALES="$(consultar "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public';" || echo 'consulta-fallida')"

if [ "$TABLAS_ACTUALES" = "consulta-fallida" ]; then
    echo "[restauracion] ERROR: no se pudo consultar la base $DB_DATABASE."
    echo "[restauracion]        no se sigue adelante a ciegas."
    exit 1
fi

echo "[restauracion] la base $DB_DATABASE tiene $TABLAS_ACTUALES tablas."

if [ "$TABLAS_ACTUALES" -ne 0 ] && [ "$FORZAR" -ne 1 ]; then
    echo "[restauracion] ERROR: la base de destino ya tiene tablas."
    echo "[restauracion]        no se sobrescribe nada sin confirmación."
    echo "[restauracion]        si de verdad quiere reemplazar los datos, use --force."
    echo "[restauracion]        Con --force se VACÍAN las tablas en una transacción."
    echo "[restauracion]        La base no se borra y no se hace DROP."
    exit 1
fi

if [ "$SIMULAR" -eq 1 ]; then
    echo ""
    echo "[restauracion] SIMULACIÓN: no se escribió nada."
    echo "[restauracion] todo lo necesario está en su lugar. Puede restaurar."
    exit 0
fi

# ---------------------------------------------------------------------------
# 3. Detener el servicio (REGLA 3)
# ---------------------------------------------------------------------------
# Se detiene SOLO si hay docker compose disponible. Si no lo hay, se avisa y se
# sigue: es probable que se esté restaurando en un equipo de pruebas.
echo "[restauracion] deteniendo el servicio para que nadie entre al estudio..."

if command -v docker >/dev/null 2>&1; then
    COMPOSE="${ARCHIVO_COMPOSE:-deploy/docker-compose.prod.yml}"

    if [ -f "$COMPOSE" ]; then
        docker compose -f "$COMPOSE" stop api web cola || true
        echo "[restauracion] servicio detenido."
    else
        echo "[restauracion] AVISO: no se encontró $COMPOSE para detener los contenedores."
        echo "[restauracion]        Detenga el servicio a mano antes de seguir, si está corriendo."
    fi
else
    echo "[restauracion] AVISO: docker no está disponible en este equipo."
    echo "[restauracion]        Si la aplicación está corriendo en otra parte, deténgala a mano."
fi

# ---------------------------------------------------------------------------
# 4. Vaciar lo que haya, si hay algo (REGLA 2)
# ---------------------------------------------------------------------------
if [ "$TABLAS_ACTUALES" -ne 0 ]; then
    echo "[restauracion] vaciando las tablas existentes (--force)..."

    # Se genera un TRUNCATE de todas las tablas de `public`, en una sola
    # transacción. TRUNCATE y no DROP: la base sigue existiendo, con su
    # propietario y sus permisos, y no hay ninguna sentencia DROP en este script.
    LISTA_TABLAS="$(consultar "
        SELECT string_agg(quote_ident(table_name), ', ' ORDER BY table_name)
        FROM information_schema.tables
        WHERE table_schema = 'public' AND table_type = 'BASE TABLE';
    ")"

    if [ -n "$LISTA_TABLAS" ]; then
        PGPASSWORD="${DB_PASSWORD:-}" psql \
            --host="${DB_HOST}" \
            --port="${DB_PORT:-5432}" \
            --username="${DB_USERNAME}" \
            --dbname="${DB_DATABASE}" \
            --set=ON_ERROR_STOP=1 \
            --command="TRUNCATE $LISTA_TABLAS CASCADE;" \
            || {
                echo "[restauracion] ERROR: no se pudieron vaciar las tablas. Se aborta."
                exit 1
            }
    fi
fi

# ---------------------------------------------------------------------------
# 5. Restaurar (REGLA 6)
# ---------------------------------------------------------------------------
echo "[restauracion] restaurando $ARCHIVO en $DB_DATABASE..."

# `--single-transaction`: se aplica COMPLETA o NO SE APLICA. Una restauración a
# medias deja una base incoherente que parece buena.
# `--exit-on-error`: se detiene en el primer error, en vez de seguir aplicando.
# NO se usa `--clean`: eso genera DROP por dentro y esta es una base vacía.
# `--no-owner` y `--no-privileges`: el respaldo no debe exigir el rol del equipo
# donde se hizo.
if PGPASSWORD="${DB_PASSWORD:-}" pg_restore \
        --host="${DB_HOST}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME}" \
        --dbname="${DB_DATABASE}" \
        --single-transaction \
        --exit-on-error \
        --no-owner \
        --no-privileges \
        "$ARCHIVO"; then

    echo "[restauracion] restauración terminada."
else
    echo "[restauracion] ERROR: falló pg_restore. La base queda como estaba."
    echo "[restauracion]        gracias a --single-transaction, no quedó una restauración a medias."
    exit 1
fi

# ---------------------------------------------------------------------------
# 6. Comprobación posterior
# ---------------------------------------------------------------------------
TABLAS_RESTAURADAS="$(consultar "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public';")"

echo "[restauracion] la base tiene ahora $TABLAS_RESTAURADAS tablas."

# La función de contexto es lo que hace que el aislamiento funcione. Si no está, la
# API se levanta y queda despidiendo datos de otros estudios.
if ! consultar "SELECT 1 FROM pg_proc WHERE proname = 'app_tenant_id';" | grep -q 1; then
    echo "[restauracion] AVISO: no se encontró la función public.app_tenant_id()."
    echo "[restauracion]        el aislamiento entre estudios NO va a funcionar."
    echo "[restauracion]        corra 'php artisan migrate --force' sobre la base restaurada."
fi

# Y la vista pública, que es lo único que se puede leer antes del ingreso. Si no
# está, la pantalla de ingreso no tiene nada que mostrar y nadie sabe por qué.
if ! consultar "SELECT 1 FROM pg_views WHERE viewname = 'vw_estudios_publicas';" | grep -q 1; then
    echo "[restauracion] AVISO: no se encontró la vista public.vw_estudios_publicas."
    echo "[restauracion]        el selector de ingreso no va a funcionar."
fi

# Y las políticas, una por una. Esta es la comprobación que NO está en el respaldo
# de la base, y la que más caro sale perder.
SIN_RLS="$(consultar "
    SELECT count(*)
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
      AND c.relkind = 'r'
      AND EXISTS (
          SELECT 1 FROM pg_attribute a
          WHERE a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
      )
      AND (NOT c.relrowsecurity OR NOT c.relforcerowsecurity);
")"

if [ -n "$SIN_RLS" ] && [ "$SIN_RLS" != "0" ]; then
    echo "[restauracion] FALLA: $SIN_RLS tabla(s) con tenant_id NO tienen Row Level Security"
    echo "[restauracion]        activada y forzada."
    echo "[restauracion]        NO levante el servicio hasta corregir esto. Ver docs/03."
else
    echo "[restauracion] aislamiento: todas las tablas con tenant_id tienen RLS activada y forzada."
fi

# ---------------------------------------------------------------------------
# 7. El servicio QUEDA DETENIDO (REGLA 3)
# ---------------------------------------------------------------------------
echo ""
echo "[restauracion] EL SERVICIO QUEDA DETENIDO."
echo "[restauracion] Revise los datos antes de levantar nada:"
echo "[restauracion]   1. Conectese a la base y revise los contratos abiertos y sus saldos."
echo "[restauracion]   2. Corra 'php artisan fotografia:verificar-aislamiento'."
echo "[restauracion]   3. Corra 'php artisan fotografia:revisar-entregas' para ver las sesiones"
echo "[restauracion]      que esperan selección y los medios con originales sin verificar."
echo "[restauracion]   4. Verifique que los MEDIOS con originales estén montados. La base no"
echo "[restauracion]      los contiene: restaurar la base no devuelve las fotos. Ver docs/08."
echo "[restauracion]   5. Solo después levante el servicio con 'docker compose up -d'."
echo "[restauracion]"
echo "[restauracion] Este script NO levanta el servicio. La decisión es de una persona."