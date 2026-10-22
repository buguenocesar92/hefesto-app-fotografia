# =============================================================================
# Hefesto Studio — Fotografía: API
# =============================================================================
# Imagen de producción: PHP con php-fpm, nginx y el worker de colas, bajo un
# único supervisor.
#
# POR QUÉ VARIAS INSTANCIAS EN UNA SOLA IMAGEN
# --------------------------------------------
# En un estudio el cuello de botella no es PHP: es esperar a que alguien responda.
# Un solo contenedor con php-fpm, nginx, el worker de colas y el planificador es
# más simple de operar, y en una instalación de este tamaño no cuesta nada frente
# a separar en varios contenedores.
#
# Si el worker de colas no corre, las revisiones de conservación y los avisos de
# selección quedan apilados. En este producto eso significa que nadie se entera de
# que un disco dejó de responder hasta que una familia viene a buscar una foto.
#
# VERSIONES
# ---------
# Las etiquetas se copiaron de `apps/peluqueria/deploy/api/Dockerfile`, que las
# consultó el 2026-10-17. En el equipo donde se escribió esta aplicación no hay
# Docker, así que NO se volvieron a comprobar contra el registro. Verificar antes
# del primer despliegue. Ver docs/10.
#
#   - php:8.4-fpm-alpine3.24
#   - postgres:18.6            (ver deploy/docker-compose.prod.yml)
#   - redis:8.8.3-alpine
#   - caddy:2.11.7-alpine
#   - node:24-alpine3.24       (ver deploy/web/Dockerfile)
# =============================================================================

# =============================================================================
# CVE-2026-109618 / CVE-2026-109619 (PHP2Shell, 2026-10-22)
# =============================================================================
# La imagen se construye con PHP 8.4.27, que es la version PARCHEADA
# de la serie 8.4. Las versiones parcheadas de PHP son
# 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12.
#
# Verificado el 2026-10-22 en el servidor de desarrollo, con el tag exacto:
#
#     $ docker run --rm php:8.4-fpm-alpine3.24 php -v
#     PHP 8.4.27 (cli) (built: Oct 21 2026 22:52:58)
#
# El tag `php:8.4-fpm-alpine3.24` es FLOTANTE: no se cambia, se deja constancia.
# El tag resuelve hoy a PHP 8.4.27, que es la version parcheada de la serie
# 8.4. Se deja escrito porque un tag flotante puede mudar sin que nadie lo
# note: la comprobacion va en el candado (api/tests/Unit/), que falla si el
# tag vuelve a quedar por debajo de la version parcheada.
#
# OJO: LA IMAGEN DESPLIEGADA HAY QUE RECONSTRUIRLA. El parche de PHP entra en
# el contenedor por la IMAGEN, no por este repositorio: mientras no se
# reconstruya y se redespliegue, el contenedor sigue con el PHP viejo. El
# endurecimiento de mas abajo (el ini y el pool de php-fpm) es la red de seguridad
# para ese mientras, y lo que sobrevive a un redeploy.
FROM php:8.4-fpm-alpine3.24 AS base

# `pcntl` lo necesita el worker de colas; `pdo_pgsql`, la conexión a PostgreSQL;
# `bcmath` e `intl`, el redondeo por línea del saldo y el formato de fechas.
# `nginx` y `supervisor` son los dos procesos que corren adentro.
RUN apk update \
    && apk upgrade \
    && apk add --no-cache \
        nginx \
        supervisor \
        libpq-dev \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        bash \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del --no-network .build-deps \
    && rm -rf /tmp/*

WORKDIR /var/www/html

# Se copian primero los manifiestos para que la capa de dependencias se
# reutilice mientras no cambien las versiones.
COPY api/composer.json api/composer.lock* ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY api/ ./

# `--optimize-autoloader` importa más de lo habitual en esta aplicación:
# `app/Http/Requests/PeticionDeFotografia.php` declara la clase base de validación
# en un archivo aparte de las clases hijas, y sin el classmap optimizado la
# imagen puede quedarse sin encontrarla. El archivo se llama así por convención
# del proyecto, no porque PSR-4 lo exija.
RUN composer install \
        --no-dev \
        --no-scripts \
        --optimize-autoloader \
        --no-interaction \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY deploy/api/docker/php.ini /usr/local/etc/php/conf.d/zz-fotografia.ini

# Endurecimiento PHP2Shell (CVE-2026-109618 / CVE-2026-109619):
# la via CONFIRMADA (session.upload_progress) apagada, y los
# trabajadores de php-fpm reciclados cada 100 peticiones.
COPY deploy/api/docker/99-php2shell-hardening.ini /usr/local/etc/php/conf.d/99-php2shell-hardening.ini
COPY deploy/api/docker/zz-php2shell-fpm.conf /usr/local/etc/php-fpm.d/zz-php2shell-hardening.conf
COPY deploy/api/docker/nginx.conf /etc/nginx/http.d/fotografia.conf
COPY deploy/api/docker/supervisord.conf /etc/supervisord.conf
COPY deploy/api/docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8000

# La salud no depende de la sesión ni del estudio: solo comprueba que PHP responde.
# Un chequeo que necesita sesión daría falso negativo durante un despliegue, y un
# chequeo que toca una tabla protegida fallaría cuando la petición no tiene
# `app.tenant_id` fijado.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8000/api/v1/salud") === false ? 1 : 0);'

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]