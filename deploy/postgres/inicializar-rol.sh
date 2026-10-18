#!/bin/bash
# ---------------------------------------------------------------------------
# Fotografía: crea el rol con el que conecta la aplicación
# ---------------------------------------------------------------------------
# ADVERTENCIA HONESTA: este script NO se ejecutó. En el equipo donde se
# escribió no había PostgreSQL ni Docker. Ver docs/03 y docs/10.
#
# POR QUÉ HAY DOS ROLES Y NO UNO
# ------------------------------
# La imagen oficial de PostgreSQL ejecuta `initdb --username="$POSTGRES_USER"`,
# y el mensaje de error de la propia imagen habla del "superuser password".
# Es decir: el rol de `POSTGRES_USER` NACE superusuario.
#
# La documentación de PostgreSQL 18 lo dice de forma textual:
#
#   "Superusers and roles with the BYPASSRLS attribute always bypass the row
#    security system when accessing a table. Table owners normally bypass row
#    security as well, though a table owner can choose to be subject to row
#    security with ALTER TABLE ... FORCE ROW LEVEL SECURITY."
#
# La segunda frase es la clave: `FORCE ROW LEVEL SECURITY` sujeta al DUEÑO de la
# tabla, pero NO al superusuario. Si la aplicación conectara con el rol de
# `POSTGRES_USER`, ninguna política se aplicaría y el aislamiento entre estudios
# quedaría decorativo. Por eso este script crea un rol aparte, que es el único que
# usa la aplicación.
#
# En un estudio esto no es un detalle teórico. Cada fila de `accesos_archivo` dice
# quién abrió los originales de las fotos de una familia, y `accesos_archivo` está
# protegida con RLS como todas las demás. Con un solo rol, esa tabla sería legible
# entre estudios.
#
# QUÉ HACE, EN ORDEN
# ------------------
# 1. Falla ruidosamente si falta alguna variable obligatoria. Es preferible un
#    despliegue detenido a una base con un rol de contraseña vacía.
# 2. Crea el rol de la aplicación con TODOS los atributos explícitos, incluidos los
#    que ya son el valor por omisión. Un rol verificado no depende de un valor por
#    omisión que puede cambiar con una versión nueva.
# 3. Le da la propiedad de la base, para que las migraciones de Laravel puedan crear
#    el esquema.
#
# POR QUÉ ES DUEÑO DE LA BASE Y NO UN ROL MÁS
# -------------------------------------------
# Las migraciones de Laravel se corren con el rol de la aplicación y necesitan crear
# tablas. La única forma de dárselo sin permisos de superusuario es que ese rol sea
# el dueño de la base. Desde PostgreSQL 15 el esquema `public` pertenece al dueño de
# la base, así que con esto el rol puede crear tablas ahí.
#
# Y aquí está el punto exacto del diseño: al ser el dueño, las tablas quedan a su
# nombre, y por eso el `FORCE ROW LEVEL SECURITY` que ya usan las tablas lo sujeta a
# él. El dueño SÍ pasa por las políticas. Un superusuario, no.
# ---------------------------------------------------------------------------

set -euo pipefail

fallar() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

# --- Variables obligatorias -----------------------------------------------

[ -n "${POSTGRES_DB:-}" ] || fallar "falta POSTGRES_DB: no se sabe que base arreglar"
[ -n "${APP_DB_ROLE:-}" ] || fallar "falta APP_DB_ROLE: no se sabe que rol crear"
[ -n "${APP_DB_PASSWORD:-}" ] || fallar "falta APP_DB_PASSWORD: no se crea un rol sin contraseña"

# --- Creación del rol -----------------------------------------------------

psql --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --no-psqlrc \
     --set=ON_ERROR_STOP=1 \
     --set=rol="$APP_DB_ROLE" \
     --set=base="$POSTGRES_DB" \
     --set=clave="$APP_DB_PASSWORD" <<'SQL'
CREATE ROLE :"rol" WITH LOGIN
    NOSUPERUSER
    NOCREATEDB
    NOCREATEROLE
    NOINHERIT
    NOBYPASSRLS
    PASSWORD :'clave';

-- El rol queda como dueño de la base para que las migraciones de Laravel puedan
-- crear el esquema. Ver la cabecera: por eso lo sujeta el
-- FORCE ROW LEVEL SECURITY y no lo salta.
ALTER DATABASE :"base" OWNER TO :"rol";
SQL

printf '[rol] el rol %s quedo creado y es dueño de la base %s\n' "$APP_DB_ROLE" "$POSTGRES_DB"
printf '[rol] el rol de POSTGRES_USER (%s) queda solo para tareas administrativas.\n' "$POSTGRES_USER"