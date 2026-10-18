# Fotografía

Aplicación para estudios de fotografía en Chile. Software propietario de
**Hefesto Studio**. No es un despliegue de una herramienta de terceros.

---

## Qué resuelve

Lo que un estudio vende es **un paquete de fotos que todavía no existe cuando se
contrata**, y lo que no puede fallar es **la fecha de entrega**. Esto no es una
imprenta: el estudio **encarga** la impresión a un laboratorio externo.

| Necesidad del estudio | Dónde vive |
|---|---|
| Un contrato que diga qué se promete y cuánto se entregó | `contratos` y `lineas_contrato` |
| Una fecha prometida que no se pueda cambiar por descuido | `sesiones`, con `fecha_prometida` y `fecha_realizada` aparte |
| Saber que hay fotos sin imprimir porque nadie ha elegido | `selecciones`, con estado propio |
| Un encargo a un laboratorio con fecha prometida y faltantes | `pedidos_laboratorio` |
| Saber dónde están los originales y si ese medio sigue vivo | `conservaciones`, con estado y revisiones |
| Saber quién debe, sin confundir entrega con pago | `CalculadoraDeSaldo`, que devuelve un desglose |

---

## Las tres reglas que la distinguen

**1. Una entrega parcial no cierra el contrato.** Entregar veinte fotos de cien
avanza el trabajo; no lo termina. El contrato se cierra solo cuando todas sus
líneas quedan completas, y eso lo decide el sistema mirando el conjunto, no quien
aprieta el botón. Ver [06 · Primer contrato](docs/06-PRIMIER-CONTRATO.md).

**2. No se marca entregada una sesión sin selección confirmada.** "Pendiente de
selección" es el estado **normal** de una sesión que se tomó y en la que la
persona todavía no eligió, y el sistema no lo trata como un error. Pero imprimir
es un encargo a un laboratorio externo que se paga por adelantado y no se
devuelve, así que sin selección `confirmada` la sesión no pasa a `entregada`.
Ver [03 · Multitenancy y roles](docs/03-MULTITENANCY-Y-ROLES.md).

**3. La conservación de los archivos no es un campo de texto.** Un estudio
entrega las fotos "de por vida", y eso obliga a poder responder dónde están y si
el medio sigue vivo. Un campo con la ruta del disco no avisa de nada: el texto
sigue ahí diciendo que todo está bien el día después de que el disco se cayó.
Por eso hay una tabla `conservaciones` con estado propio, revisiones escritas y
un trabajo que revisa. Ver [08 · Respaldos](docs/08-RESPALDOS.md).

---

## Estado: no se ejecutó nunca

En el equipo donde se escribió esta aplicación **no hay PHP, Composer, Docker ni
PostgreSQL**. Hay cero migraciones aplicadas, cero pruebas corridas, cero imágenes
construidas y cero peticiones HTTP hechas.

Lo que sí se comprobó está en [10 · Pruebas y estado](docs/10-PRUEBAS-Y-ESTADO.md),
que lo dice uno por uno.

---

## Cómo se organiza

```
apps/fotografia/
  api/                      API Laravel 13 sobre PostgreSQL
  web/                      Interfaz Nuxt 4 con render en el servidor
  deploy/                   Dockerfiles, compose, Caddy, roles y respaldos
  docs/                     Once documentos numerados
```

Empiece por el [índice de la documentación](docs/00-INDICE.md).

---

## Puesta en marcha

El camino corto, en desarrollo:

```bash
cd apps/fotografia
cp api/.env.example api/.env          # complete los valores
cp web/.env.example web/.env

docker compose -f deploy/docker-compose.dev.yml up -d

docker compose -f deploy/docker-compose.dev.yml exec api \
    php artisan fotografia:verificar-aislamiento
```

El último comando es **obligatorio** antes de recibir la primera sesión: comprueba
que el aislamiento entre estudios está activo de verdad, y no solo escrito en una
migración.

El detalle completo está en [04 · Instalación](docs/04-INSTALACION.md).

---

## Licencia

Software propietario de Hefesto Studio. Todos los derechos reservados.

© 2026 Hefesto Studio.