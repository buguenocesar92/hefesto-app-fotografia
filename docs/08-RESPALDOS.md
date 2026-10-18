# 08 · Respaldos

Qué se guarda, qué **no** se guarda, y por qué nunca hay `DROP` en estos
scripts.

---

## Lo primero: qué NO se respalda

Hay que decirlo antes que cualquier otra cosa, porque es lo que más duele cuando
falta.

> **Este sistema no respalda los originales de las fotos.**

Los originales viven en el medio declarado en `conservaciones`, que es un disco o
una red, **no una tabla**. `respaldo.sh` hace un `pg_dump`: respalda la base. Los
archivos están en otro lado.

La copia de los originales es **otra operación, distinta**, con otras reglas, y es
la que de verdad importa. Por eso al final de cada corrida el script escribe:

```
[respaldo] recordatorio: este respaldo sigue en el servidor.
[respaldo] falta copiarlo fuera y probar una restauración completa.
[respaldo] y falta respaldar los originales de las fotos, que no están aquí.
```

Y el manifiesto dice lo mismo:

```
nota: este respaldo contiene la BASE. Los originales de las fotos viven
nota: fuera de ella, en el medio declarado en la tabla 'conservaciones'.
nota: copiar esos originales es otra operación, distinta.
```

---

## Por qué este respaldo importa más que en otros sistemas

Perder la base aquí no es perder una lista de clientes. Es perder la respuesta a
"el cumple de la familia Pérez fue el 14 de diciembre, se entregaron 80 de 100
fotos y aún hay un abono pendiente".

Los contratos, las fechas prometidas, la selección y la caja son **la evidencia
con la que se responde un reclamo**. Borrarlos deja números que no cuadran.

---

## Las seis reglas

### 1. Manifiesto con SHA-256 de cada archivo

Un respaldo sin suma de verificación es una carpeta con archivos: no hay forma de
saber si se copió entero ni si se dañó al guardarlo.

El manifiesto guarda el SHA-256, el tamaño, la base, el servidor, el puerto, la
versión de `pg_dump` y la fecha. Permite verificar un respaldo meses después,
cuando el disco ya cambió.

### 2. `COMPLETO` se escribe SOLO al final

El archivo `COMPLETO` se escribe cuando todo terminó bien. Mientras corre, hay un
`EN_CURSO`.

Si el script se corta a la mitad, el directorio queda sin la marca y se sabe que no
sirve, en vez de descubrirlo cuando hay que restaurar.

Y hay una comprobación más: después de comprimir, se corre `gzip -t` sobre el
archivo. Un respaldo tiene que poder **leerse** antes de dárselo por bueno.

### 3. Se validan las rutas ANTES de escribir

Se comprueba que el destino **existe**, que se puede escribir y que hay espacio
libre (con un margen de 500 MB para el temporal y el manifiesto).

Y el destino **no se crea**:

```
[respaldo] ERROR: el destino no existe: $DESTINO
[respaldo]        créelo a mano y vuelva a correr. El script no lo crea a propósito:
[respaldo]        un mkdir -p sobre una ruta mal escrita deja el respaldo donde nadie lo busca.
```

Un `mkdir -p` a una ruta equivocada es la forma clásica de escribir 40 GB en un
sistema de archivos equivocado.

### 4. Destino ocupado se rechaza, salvo `--force`

El nombre lleva fecha y hora. Que se repita significa que dos respaldos corrieron
en el mismo segundo, o que alguien reintenta. Sin `--force` no se sobrescribe
**nada**.

### 5. Nunca hay `DROP` ni sobreescritura automática

Un respaldo que borra el anterior es un respaldo que se queda sin respaldo. La
retención borra solo archivos **viejos**, y dice cuáles va a borrar antes.

### 6. La retención va DESPUÉS de verificar, y avisa

Este es el detalle que más se olvida:

```
  1. volcar
  2. comprimir
  3. verificar con gzip -t
  4. escribir el manifiesto con SHA-256
  5. quitar EN_CURSO
  6. escribir COMPLETO      <-- acá
  7. aplicar retención
```

La marca `COMPLETO` va **antes** de la retención, a propósito. Si el `rm -rf`
fallaba por permisos o por disco lleno, el respaldo nuevo, sano y verificado,
quedaba sin marcar y se tomaba por incompleto. **Purgar la retención no puede
dejar en duda la validez de lo recién creado.**

Y antes de borrar, se imprime cada carpeta:

```
[respaldo]   se va a eliminar: /var/backups/fotografia/fotografia-20260101-020000
```

La retención es lo único que borra en este script, y borra respaldos enteros:
conviene verlo.

---

## Cómo se usa

```bash
deploy/respaldos/respaldo.sh [--force]
```

| Variable | Por omisión | Nota |
|---|---|---|
| `DIRECCION_RESGARDOS` | `/var/backups/fotografia` | Debe existir. El script no la crea |
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` | — | Obligatorias |
| `RETENCION_DIAS` | `30` | |

El destino guarda:

```
  fotografia-20260115-030000/
    datos.dump.gz
    manifiesto.txt
    COMPLETO
```

---

## La restauración

```bash
deploy/respaldos/restauracion.sh <carpeta-de-respaldo>
```

### Lo que hace, en orden

1. **Verifica el SHA-256 contra el manifiesto ANTES de tocar la base.** Si no
   calza, se detiene. Restaurar un respaldo dañado sobre una base sana es la peor
   forma de perder datos: se pierde lo bueno y lo malo.
2. **Pide confirmación** explícita.
3. Restaura.
4. **Deja el servicio detenido al terminar.** No se vuelve a levantar solo: quien
   restauró tiene que mirar lo que pasó antes de que nadie más escriba encima.
5. **Comprueba que la base restaurada conserva el aislamiento**: que las tablas
   siguen con RLS forzada, que la vista pública sigue con sus tres columnas y que
   la función sigue existiendo.

El punto 5 es el que más importa y el que casi nadie hace. **Un respaldo de una
base a la que le falta el aislamiento es un respaldo que restaura el problema.**
La Row Level Security se instala con las migraciones, y una restauración parcial
puede dejarla sin las políticas.

### Por qué no usa `--clean`

Porque `--clean` implica `DROP`, y el encabezado de este proyecto dice que nunca
hay `DROP`. Es una de las cosas que se corrigieron del material de referencia: ver
[10](10-PRUEBAS-Y-ESTADO.md).

---

## La copia externa es obligatoria

`respaldo.sh` **no copia nada a otro lado**, a propósito:

```
[respaldo] recordatorio: este respaldo sigue en el servidor.
```

Cada instalación decide a dónde va su copia: una NAS, un servicio de objetos, otro
servidor. Esa decisión no se puede tomar desde un script genérico.

> Un respaldo que no se copia fuera del servidor no es un respaldo: es un archivo.

---

## El plan de conservación de los originales

Esto es lo que hay que decidir antes de recibir la primera sesión, y **el software no
lo resuelve**.

| Qué hay que definir | Por qué |
|---|---|
| Qué medio declara cada estudio | `disco_local`, `red_area_local`, `almacenamiento_nube`, `cinta` |
| Cuántas copias | Por lo menos dos, en medios distintos |
| Cada cuánto se copia | No se pudo verificar un número |
| Cómo se verifica la copia | Un SHA-256, como el del respaldo de la base |
| Qué pasa si el medio se daña | El sistema marca `danada` y avisa; la copia es la que salva |
| Cómo se prueba una restauración | **Restorear es lo que prueba un respaldo, no el respaldo mismo** |

La tabla `conservaciones` con su estado, sus revisiones y su lista cerrada de
medios existe para que estas respuestas no estén en un papel suelto.

Ver `deploy/respaldos/respaldo.sh` y `restauracion.sh`, y
`api/app/Jobs/VerificarConservacionJob.php`.

---

© 2026 Hefesto Studio. Software propietario.