# 10 · Pruebas y estado

Este es el documento que dice qué se hizo y qué **no** se hizo. Se escribe para
ser leído antes que los otros.

---

## Lo primero, en una línea

> **Esta aplicación nunca se ejecutó.** No hay PHP, Composer, Docker ni PostgreSQL
> en el equipo donde se escribió.

De ahí sale todo lo demás:

| | |
|---|---|
| Migraciones aplicadas | **0** de 12 |
| Pruebas corridas | **0** |
| Imágenes construidas | **0** |
| Contenedores levantados | **0** |
| Comandos ejecutados | **0** |

Lo que sí se hizo: se escribió el código, se revisó a mano la correspondencia
entre modelos y migraciones, y se verificaron las tres cosas que no necesitan
PHP.

---

## Por qué no se ejecutó

Faltan las cuatro herramientas y **no se podían instalar**: instalar software de
sistema en la máquina del usuario requiere su aprobación, y no se pidió porque
escribiendo la aplicación no era indispensable.

Sin esas herramientas, correr una migración o una prueba no era posible. No se
corrigió falseando el resultado: se declaró.

---

## Qué SÍ se verificó (sin PHP)

### La correspondencia entre modelos y migraciones

Cada uno de los **17 modelos** se cruzó contra las **12 migraciones**, comparando
el `$fillable` de cada uno contra las columnas que las migraciones crean de
verdad.

**Resultado: 0 inconsistencias.** Los 17 modelos usan únicamente columnas que
existen.

Esa revisión **no sustituye** a correr las migraciones: puede haber una columna que
existe con el tipo equivocado, un índice que no se crea, o una clave foránea que
apunta a la tabla equivocada. Lo que sí garantiza es que no hay un campo en un
modelo que la base no tenga.

### Los alfabetos prohibidos

Se recorrieron los **168 archivos** de la aplicación buscando caracteres de
alfabetos que no corresponden al español: chino, cirílico, árabe, hebreo,
coreano, tailandés, armenio y devanagari.

**Resultado: 0 caracteres.**

### Los scripts de shell

`bash -n` sobre `respaldo.sh` y `restauracion.sh`, y el YAML de los dos compose
los revisa el validador. Ver más abajo.

---

## Lo que NO se verificó

### 1. Las versiones

Todas se copiaron de las otras aplicaciones de Hefesto Studio:

| | Declarado | Estado |
|---|---|---|
| PHP | 8.4 | **sin verificar** |
| Laravel | ^13.37 | **sin verificar** contra Packagist |
| Node | 24 | **sin verificar** |
| Nuxt | 4.6.2 | **sin verificar** |
| PostgreSQL | 18.6 | **sin verificar** |
| Redis | 8.8.3 | **sin verificar** |
| Caddy | 2.11.7 | **sin verificar** |
| Authentik | 2025.10 | **sin verificar** |

No se consultó ningún registro de paquetes ni de imágenes de contenedor. Si una de
estas etiquetas no existe, el primer `docker compose build` lo dirá. No se puede
decir acá cuál es la correcta, porque no se miró.

### 2. El código de PHP y TypeScript

No hay compilador, así que **no se comprobó** que el código no tenga errores de
sintaxis, de tipos ni de referencias a clases inexistentes.

Se revisó a mano lo que se pudo: los `use` de cada archivo, los namespaces y las
firmas de los servicios. Pero una revisión a mano no es un compilador.

### 3. Los seis plazos

| Plazo | Por qué no tiene número |
|---|---|
| Días de promesa de entrega | Es una promesa comercial, no una regla técnica |
| Días de plazo de laboratorio | Se pacta con cada laboratorio y cambia por temporada |
| Días de espera de selección | Depende del tipo de sesión |
| Días sin verificar una conservación | Un número inventado produce avisos falsos |
| Retención de la bitácora de accesos | Es una decisión del cliente con su revisión legal |
| Fotos de más sin pagar | Qué pasa con las fotos de más es del estudio, no del software |

Los seis nacen en `null` y la pantalla los declara. Ver
[05](05-CONFIGURACION.md) y [07](07-SEGURIDAD-Y-LEY-19628.md).

### 4. La lectura de los archivos originales

**No está implementada.** `FotoArchivoController` devuelve la ficha del original
(nombre, bytes, huella SHA-256), no el binario.

No se pudo verificar cómo se montan los medios declarados en `conservaciones`, y
no se iba a inventar un montaje. Ver [08](08-RESPALDOS.md).

### 5. El uso de la ruta de salud

`/api/v1/salud` está declarada en `bootstrap/app.php`. **No se verificó** que el
proveedor de despliegue la use como ruta de comprobación.

### 6. El plan de copia de los originales

Los scripts de respaldo **no copian nada fuera del servidor**, a propósito: cada
instalación decide a dónde va su copia. Y no hay un plan de conservación de los
originales de las fotos, porque eso depende de los medios que use cada estudio.

Ver [08](08-RESPALDOS.md).

---

## Las pruebas que están escritas

Cinco archivos. **Ninguna se corrió.**

Se pueden correr solas:

```bash
php artisan test --testsuite=Aislamiento
php artisan test --testsuite=Cobranza
php artisan test --testsuite=Contratos
php artisan test --testsuite=Sesiones
php artisan test --testsuite=Unit
```

### `Unit/Cobranza/CalculadoraDeSaldoTest.php`

Prueba **pura**, sin base de datos. Es la única de las cinco que se puede correr
sin PostgreSQL, y es la que mejor está: `CalculadoraDeSaldo` no toca Eloquent ni
SQL, recibe estructuras planas y devuelve el desglose. Por eso su prueba es una
unidad de verdad y no una prueba que necesita la base entera.

### `Feature/Aislamiento/AislamientoDeDatosTest.php`

Crea dos estudios con filas reales, lee con el contexto de uno y verifica que el
otro no aparece. Y hace la comprobación inversa: **escribir una fila declarando
el estudio de otro**. Sin `WITH CHECK` esa escritura pasa, la lectura la oculta y
nadie la ve nunca.

> **La base de pruebas tiene que ser PostgreSQL, no SQLite.** En SQLite estas
> pruebas pasarían sin comprobar nada: un sistema sin aislamiento alguno también
> daría verde, porque SQLite no tiene RLS que lo niegue. Está declarado en
> `phpunit.xml`.

### `Feature/Contratos/EntregaParcialNoCierraContratoTest.php`

Verifica que una entrega parcial sube `cantidad_entregada`, deja el contrato
`activo`, y que el contrato se cierra solo cuando todas las líneas están
completas.

### `Feature/Sesiones/NoSeMarcaEntregadaSinSeleccionTest.php`

Verifica que una sesión sin selección `confirmada` no se puede marcar entregada,
y que la razón se expone como `motivo_de_no_poder_entregar`.

### `Feature/Cobranza/SaldoPendienteTest.php`

Verifica que el saldo se calcula sobre `contratos.precio_total` y no sobre lo
entregado, y que `saldo_pendiente` y `saldo_a_favor` nunca salen negativos.

---

## El validador del proyecto

```bash
python tools/acentuar.py --aplicar apps/fotografia
python tools/validar_apps.py apps/fotografia
```

`acentuar.py` revisa la ortografía de los `.md`. `validar_apps.py` revisa la
estructura, el YAML de los compose, la sintaxis de los scripts de shell con
`bash -n`, que no haya secretos versionados, los caracteres no permitidos, que
haya RLS, y que en producción no se publiquen puertos de base de datos.

**Ninguno de los dos se corrió en esta sesión.** Es lo primero que hay que hacer
con esta aplicación.

---

## Errores factuales encontrados en el material de referencia

Se siguió el patrón de aislamiento de las otras aplicaciones de Hefesto Studio.
Al revisarlo aparecieron cuatro errores que **no** se copiaron.

### 1. `apps/clinica-dental`: una vista pública que no se usa

`vw_consultorios_publicos` se crea en las migraciones y **nunca se consulta**. El
propio comentario del archivo lo admite.

Una vista que no se usa es una vista que nadie revisa. El día que se le agregue una
cuarta columna, que es el momento en que se vuelve una fuga de datos, **nadie se
va a enterar** porque no está en ninguna ruta.

En Fotografía la vista **se usa de verdad**: en `AuthController::estudios()`,
en `AuthController::entrar()` y en `ProveedorOidcAuthentik::usuarioDesdeClaims()`.
Y el comando de verificación **cuenta las columnas y exige que sean exactamente
tres**.

### 2. `apps/clinica-dental`: el verificador no comprueba lo importante

Su `VerificarAislamientoCommand` **no comprueba** que el rol de la aplicación:

- no sea superusuario;
- no tenga `BYPASSRLS`;

ni que la vista pública siga teniendo las columnas que debería.

Es el **modo de falla más silencioso que existe**. Si la aplicación conecta como
superusuario, **todas las políticas están creadas, todas las comprobaciones de
estructura pasan, y el aislamiento no existe**. Un superusuario siempre saltea la
Row Level Security, aunque la tabla tenga `FORCE`.

En Fotografía, `fotografia:verificar-aislamiento` hace **seis** comprobaciones, y
las dos primeras son exactamente estas.

### 3. `apps/clinica-dental`: `restauracion.sh` contradice su propio encabezado

El encabezado dice que **nunca hay `DROP`**, y el script usa:

```
pg_restore --clean --if-exists
```

`--clean` implica `DROP`. El encabezado y el código dicen cosas distintas, y en un
script de restauración la contradicción se resuelve mirando el código, no el
comentario.

En Fotografía, `restauracion.sh` **no usa `--clean`**, y además verifica el SHA-256
contra el manifiesto **antes de tocar la base**.

### 4. `apps/clinica-dental`: la documentación invoca un comando que no existe

La documentación manda a correr `clinica-dental:verificar-aislamiento`, y la firma
real es `clinica:verificar-aislamiento`.

Un comando que no existe responde con un error de Laravel, no con el resultado que
la persona esperaba. Es el peor tipo de error: uno que parece un problema del
entorno y no del nombre.

---

## Qué haría falta para poder decir "funciona"

En este orden:

1. Correr `acentuar.py` y `validar_apps.py`.
2. `composer install` y `npm install`: ahí se ven los números de versión que no
   están verificados.
3. Levantar PostgreSQL y Redis.
4. `php artisan migrate`: la primera vez que las 12 migraciones corren de verdad.
5. `php artisan fotografia:verificar-aislamiento`.
6. Correr las cinco pruebas.
7. `npm run build` y levantar los contenedores.
8. Recién ahí, y solo ahí, tiene sentido hablar de que funciona.

Los pasos 2 a 7 **no se hicieron**.

---

© 2026 Hefesto Studio. Software propietario.