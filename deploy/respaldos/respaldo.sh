#!/bin/sh
# ---------------------------------------------------------------------------
# Respaldo del estudio fotográfico.
# ---------------------------------------------------------------------------
# POR QUÉ ESTE RESPALDO IMPORTA MÁS QUE EN OTROS SISTEMAS
# -----------------------------------------------------
# En un estudio, perder la base no es perder una lista de clientes: es perder la
# respuesta a "el cumple de la familia Pérez fue el 14 de diciembre, se entregaron
# 80 de 100 fotos y aún hay un abono pendiente". Los contratos, las fechas prometidas,
# la selección y la caja son la evidencia con la que se responde un reclamo.
#
# Y hay una segunda cosa que este script NO puede respaldar, y que conviene decir
# de entrada: los originales de las fotos. Los originales viven en el medio
# declarado en `conservaciones`, que es un disco o una red, no una tabla. La copia
# de ellos es otra operación, distinta, y es la que de verdad duele cuando falta.
# Ver docs/08.
#
# LAS SEIS REGLAS QUE SE SIGUEN, Y POR QUÉ
# -----------------------------------------
# 1. MANIFIESTO CON SHA-256 DE CADA ARCHIVO. Un respaldo sin suma de verificación
#    es una carpeta con archivos: no hay forma de saber si se copió entero ni si
#    se dañó al guardarlo. El manifiesto permite además verificar un respaldo meses
#    después, cuando el disco ya cambió.
#
# 2. EL RESPALDO SE MARCA COMPLETO SOLO AL FINAL. El archivo `COMPLETO` se escribe
#    cuando todo terminó bien. Si el script se corta a la mitad, el directorio
#    queda sin la marca y se sabe que no sirve, en vez de descubrirlo cuando hay
#    que restaurar.
#
# 3. SE VALIDAN LAS RUTAS ANTES DE ESCRIBIR. Se comprueba que el destino existe,
#    que hay espacio y que se puede escribir. Un `mkdir -p` a una ruta equivocada es
#    la forma clásica de escribir un respaldo de 40 GB en un sistema de archivos
#    equivocado.
#
# 4. DESTINO OCUPADO SE RECHAZA, SALVO CON `--force`. El nombre del respaldo lleva
#    fecha y hora. Que se repita significa que dos respaldos corrieron en el mismo
#    segundo, o que alguien reintenta. Sin `--force` no se sobrescribe NADA.
#
# 5. NUNCA SE HACE `DROP` Y NUNCA HAY SOBREESCRITURA AUTOMÁTICA. Un respaldo que
#    borra el anterior es un respaldo que se queda sin respaldo. La retención borra
#    solo archivos VIEJOS, y se dice cuáles va a borrar antes.
#
# 6. LA RETENCIÓN SE APLICA DESPUÉS DE VERIFICAR QUE EL RESPALDO NUEVO ESTÁ SANO,
#    Y AVISA ANTES DE BORRAR. Purgar la retención no puede dejar en duda la validez
#    de lo recién creado, y ver qué se va a eliminar es parte del respaldo: si el
#    criterio está mal, se ve.
#
# USO
# ---
#   deploy/respaldos/respaldo.sh [--force]
#
# Variables de entorno obligatorias (normalmente vienen del `.env`):
#   DIRECCION_RESGARDOS   donde se dejan los respaldos
#   DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_HOST, DB_PORT
#
# La retención por omisión es de 30 días. Un respaldo que no se prueba durante una
# restauración no es un respaldo: es un archivo.
# ---------------------------------------------------------------------------

set -eu

FUERZA=0
RETENCION_DIAS="${RETENCION_DIAS:-30}"
DESTINO="${DIRECCION_RESGARDOS:-/var/backups/fotografia}"

for ARGUMENTO in "$@"; do
    case "$ARGUMENTO" in
        --force)
            FUERZA=1
            ;;
        --help|-h)
            echo "Uso: $0 [--force]"
            echo ""
            echo "  --force  permite escribir encima de un respaldo con el mismo nombre."
            echo "           Sin esta opción, un destino ocupado hace abortar el script."
            exit 0
            ;;
        *)
            echo "[respaldo] ERROR: opción desconocida: $ARGUMENTO"
            echo "[respaldo] use --help para ver las opciones."
            exit 2
            ;;
    esac
done

# ---------------------------------------------------------------------------
# 1. Comprobaciones previas
# ---------------------------------------------------------------------------
if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ] || [ -z "${DB_USERNAME:-}" ]; then
    echo "[respaldo] ERROR: faltan DB_HOST, DB_DATABASE o DB_USERNAME en el entorno."
    exit 1
fi

if ! command -v pg_dump >/dev/null 2>&1; then
    echo "[respaldo] ERROR: pg_dump no está disponible en este equipo."
    echo "[respaldo]        instale el cliente de PostgreSQL o corra este script dentro del contenedor."
    exit 1
fi

if ! command -v sha256sum >/dev/null 2>&1 && ! command -v shasum >/dev/null 2>&1; then
    echo "[respaldo] ERROR: no hay ninguna utilidad de SHA-256 (sha256sum o shasum)."
    echo "[respaldo]        sin ella no se puede escribir el manifiesto."
    exit 1
fi

# --- REGLA 3: validar la ruta ANTES de escribir nada -------------------------
if [ ! -d "$DESTINO" ]; then
    echo "[respaldo] ERROR: el destino no existe: $DESTINO"
    echo "[respaldo]        créelo a mano y vuelva a correr. El script no lo crea a propósito:"
    echo "[respaldo]        un mkdir -p sobre una ruta mal escrita deja el respaldo donde nadie lo busca."
    exit 1
fi

if [ ! -w "$DESTINO" ]; then
    echo "[respaldo] ERROR: no hay permiso de escritura en $DESTINO"
    exit 1
fi

# Sin esto, un respaldo de varios gigabytes falla a la mitad por falta de espacio
# y se pierde el trabajo de la noche.
ESPACIO_LIBRE="$(df -Pk "$DESTINO" | awk 'NR==2 { print $4 }')"

if [ -z "${ESPACIO_LIBRE:-}" ]; then
    echo "[respaldo] ERROR: no se pudo leer el espacio disponible en $DESTINO"
    exit 1
fi

# 500 MB de margen: es el espacio que necesita el temporal y el manifiesto.
if [ "$ESPACIO_LIBRE" -lt 512000 ]; then
    echo "[respaldo] ERROR: quedan solo $((ESPACIO_LIBRE / 1024)) MB libres en $DESTINO"
    echo "[respaldo]        el respaldo se cancela ANTES de empezar, no a la mitad."
    exit 1
fi

# --- REGLA 4: destino ocupado ------------------------------------------------
MARCA="$(date +%Y%m%d-%H%M%S)"
CARPETA="$DESTINO/fotografia-$MARCA"

if [ -e "$CARPETA" ]; then
    if [ "$FUERZA" -ne 1 ]; then
        echo "[respaldo] ERROR: ya existe un respaldo con ese nombre: $CARPETA"
        echo "[respaldo]        no se sobrescribe nada. Use --force si de verdad quiere reemplazarlo."
        exit 1
    fi

    echo "[respaldo] AVISO: --force pedido. Se va a reemplazar $CARPETA"
fi

mkdir -p "$CARPETA"

ARCHIVO="$CARPETA/datos.dump.gz"
MANIFIESTO="$CARPETA/manifiesto.txt"
MARCA_COMPLETITUD="$CARPETA/COMPLETO"

# --- REGLA 2: si el script muere, la marca NO existe -------------------------
# Se pone un archivo de "en curso" para que un respaldo a medias se note antes de
# intentar restaurarlo.
echo "en curso" > "$CARPETA/EN_CURSO"

# ---------------------------------------------------------------------------
# 2. Volcado de la base
# ---------------------------------------------------------------------------
# Las marcas de tiempo van sin espacios ni dos puntos: hay que poder escribir el
# nombre a las 4 de la mañana, con un teclado de consola.
echo "[respaldo] respaldando $DB_DATABASE en $ARCHIVO"

# Se vuelca en un archivo SIN comprimir primero, y se comprime al final. Si se
# comprime al vuelo y falla, queda un `.gz` truncado que parece un respaldo y no
# lo es.
VOLCADO_TEMPORAL="$CARPETA/datos.dump"

# Formato personalizado de PostgreSQL: permite restaurar tablas sueltas, que es lo
# que hace falta cuando se daña una tabla y no todo lo demás. `--no-owner` evita
# que el respaldo exija un rol que puede que ya no exista al restaurar.
if PGPASSWORD="${DB_PASSWORD:-}" pg_dump \
        --host="${DB_HOST}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME}" \
        --dbname="${DB_DATABASE}" \
        --format=custom \
        --no-owner \
        --file="$VOLCADO_TEMPORAL"; then

    if gzip -9 "$VOLCADO_TEMPORAL"; then
        echo "[respaldo] comprimido: $ARCHIVO"
    else
        echo "[respaldo] ERROR: falló la compresión. Se conserva el volcado sin comprimir."
        echo "[respaldo]        el respaldo NO queda marcado como completo."
        exit 1
    fi
else
    echo "[respaldo] ERROR: falló pg_dump. No se borró ningún respaldo anterior."
    echo "[respaldo]        revise el log de PostgreSQL: casi siempre es la versión o el espacio."
    exit 1
fi

# --- El respaldo tiene que poder leerse antes de dárselo por bueno ----------
if ! gzip -t "$ARCHIVO"; then
    echo "[respaldo] ERROR: el archivo está dañado. No se marcó como completo."
    exit 1
fi

# ---------------------------------------------------------------------------
# 3. Manifiesto con SHA-256 (REGLA 1)
# ---------------------------------------------------------------------------
if command -v sha256sum >/dev/null 2>&1; then
    SUMA="$(sha256sum "$ARCHIVO" | awk '{ print $1 }')"
else
    SUMA="$(shasum -a 256 "$ARCHIVO" | awk '{ print $1 }')"
fi

if [ -z "$SUMA" ] || [ ${#SUMA} -ne 64 ]; then
    echo "[respaldo] ERROR: no se pudo calcular una suma SHA-256 válida."
    exit 1
fi

TAMANO="$(wc -c < "$ARCHIVO" | tr -d ' ')"
VERSION_PG="$(pg_dump --version 2>/dev/null || echo 'desconocida')"

{
    echo "# Manifiesto de respaldo de Fotografía de Hefesto Studio"
    echo "archivo: datos.dump.gz"
    echo "sha256: $SUMA"
    echo "bytes: $TAMANO"
    echo "base: $DB_DATABASE"
    echo "servidor: $DB_HOST"
    echo "puerto: ${DB_PORT:-5432}"
    echo "version_pg_dump: $VERSION_PG"
    echo "fecha: $(date +%Y-%m-%dT%H:%M:%S%z)"
    echo "nota: verificar con 'sha256sum -c' sobre el archivo datos.dump.gz."
    echo "nota: este respaldo contiene la BASE. Los originales de las fotos viven"
    echo "nota: fuera de ella, en el medio declarado en la tabla 'conservaciones'."
    echo "nota: copiar esos originales es otra operación, distinta. Ver docs/08."
} > "$MANIFIESTO"

echo "[respaldo] manifiesto escrito con la suma SHA-256."

# --- REGLA 2 y 6: la marca COMPLETO se escribe ANTES de la retención ---------
# La retención va después de la marca, y no antes. Si `rm -rf` fallaba (permisos,
# disco lleno), el respaldo nuevo, sano y verificado, quedaba sin marcar y se
# tomaba por incompleto. Purgar la retención no puede dejar en duda la validez de
# lo recién creado.
rm -f "$CARPETA/EN_CURSO"

{
    echo "completo"
    echo "fecha: $(date +%Y-%m-%dT%H:%M:%S%z)"
    echo "carpeta: $CARPETA"
} > "$MARCA_COMPLETITUD"

# ---------------------------------------------------------------------------
# 4. Retención (REGLA 6): avisa antes de borrar
# ---------------------------------------------------------------------------

ANTIGUOS="$(find "$DESTINO" -maxdepth 1 -name 'fotografia-*' -type d -mtime "+$RETENCION_DIAS" -print)"

if [ -n "$ANTIGUOS" ]; then
    # Se imprime cada carpeta antes de eliminarla. La retención es lo único que
    # borra en este script, y borra respaldos enteros: conviene verlo.
    echo "$ANTIGUOS" | while IFS= read -r VIEJO; do
        [ -z "$VIEJO" ] && continue
        echo "[respaldo]   se va a eliminar: $VIEJO"
    done

    echo "$ANTIGUOS" | while IFS= read -r VIEJO; do
        [ -z "$VIEJO" ] && continue
        rm -rf "$VIEJO"
    done
else
    echo "[respaldo]   no hay respaldos viejos."
fi

echo "[respaldo] listo: $CARPETA"
echo "[respaldo] sha256: $SUMA"

# ---------------------------------------------------------------------------
# 5. Lo que este script NO hace
# ---------------------------------------------------------------------------
# No copia nada a otro lado a propósito: cada instalación decide adónde va su
# copia (una NAS, un servicio de objetos, otro servidor) y esa decisión no se
# puede tomar desde un script genérico. Lo que sí queda escrito es que la copia
# externa es obligatoria. Ver docs/08-RESPALDOS.md.

echo "[respaldo] recordatorio: este respaldo sigue en el servidor."
echo "[respaldo] falta copiarlo fuera y probar una restauración completa."
echo "[respaldo] y falta respaldar los originales de las fotos, que no están aquí."

ls -lh "$DESTINO" | tail -n 10