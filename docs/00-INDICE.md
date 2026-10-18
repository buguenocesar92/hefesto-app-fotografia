# 00 · Índice

Fotografía: aplicación para estudios de fotografía en Chile. Este es el mapa de
la documentación y, sobre todo, el orden en que conviene leerla.

---

## Antes de nada

Si solo va a leer dos cosas, que sean estas:

1. **[10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md)**. Esta aplicación **nunca
   se ejecutó**. No hay PHP, Composer, Docker ni PostgreSQL en el equipo donde se
   escribió. Hay cero migraciones aplicadas, cero pruebas corridas y cero imágenes
   construidas. Ese documento lo dice uno por uno, sin adornos.
2. **[07 · Seguridad y Ley 19.628](07-SEGURIDAD-Y-LEY-19628.md)**. Aquí se
   guardan datos personales de clientes y, sobre todo, **fotos de personas**. Y
   las fotos de menores lo son con más razón. Dice qué se guardó, quién puede
   verlo, qué queda anotado y qué decisiones tiene que tomar el estudio con su
   revisión legal.

---

## Los once documentos

| # | Documento | De qué trata | Cuándo leerlo |
|---|---|---|---|
| 00 | Este índice | El mapa y el orden de lectura | Ahora |
| 01 | [Arquitectura](01-ARQUITECTURA.md) | Las tres capas, por qué el navegador nunca habla con la API, cómo entra la sesión | Antes de tocar código |
| 02 | [Modelo de datos](02-MODELO-DE-DATOS.md) | Las diecisiete tablas, las reglas que las atan y **lo que quedó sin definir** | Antes de tocar el esquema |
| 03 | [Multitenancy y roles](03-MULTITENANCY-Y-ROLES.md) | RLS, los dos roles de base de datos, la vista pública, los tres roles de aplicación, por qué un rechazo es 404 | Antes de tocar permisos |
| 04 | [Instalación](04-INSTALACION.md) | De cero a funcionando, y qué comprobar en el camino | Cuando la monte |
| 05 | [Configuración](05-CONFIGURACION.md) | Cada variable, qué hace, y **por qué tantas nacen vacías** | Antes de configurar |
| 06 | [Primer contrato](06-PRIMIER-CONTRATO.md) | Del estudio vacío al primer paquete entregado y cobrado | La primera vez |
| 07 | [Seguridad y Ley 19.628](07-SEGURIDAD-Y-LEY-19628.md) | Datos personales, fotos de menores, auditoría y plazos por decidir | **Antes que nada** |
| 08 | [Respaldos](08-RESPALDOS.md) | Qué se guarda, qué **no** se guarda, y por qué nunca hay `DROP` | Antes de producción |
| 09 | [API](09-API.md) | Las rutas con su permiso y su respuesta | Al integrar |
| 10 | [Pruebas y estado](10-PRUEBAS-Y-ESTADO.md) | Qué se probó, qué no, y qué falta verificar | **Siempre** |

---

## Cómo está organizado el código

```
apps/fotografia/
  api/                      API Laravel 13 sobre PostgreSQL
    app/
      Enums/                Los estados posibles, en PHP y no en comentarios
      Exceptions/           ErrorDeFotografia: el error que el dominio puede mostrar
      Http/                 Middleware, Requests, Controllers y Policies
      Jobs/                 Revisión de conservación y de selección pendiente
      Models/               Eloquent, con Concerns/PerteneceAlEstudio
      Policies/             Quién puede hacer qué, tabla por tabla
      Services/
        Auth/               OIDC contra Authentik
        Cobranza/           El desglose del saldo y su cálculo, sin base de datos
        Conservacion/       Qué medio hay que mirar y por qué
        Contratos/          La entrega parcial, que no cierra el contrato
        Laboratorio/        El encargo a un tercero, con sus faltantes
        Permisos/           Capacidades por rol
        Seleccion/          Lo que la persona eligió
        Sesiones/           Los estados y la regla de no entregar sin selección
        Tenancy/            Contexto de estudio y bitácora de accesos
    database/migrations/    Doce migraciones, del 2026-10-18
    routes/web.php          Toda la superficie de la API, versionada en una línea
    tests/                  Unitarias, de aislamiento, de cobranza, contratos y sesiones
  web/                      Interfaz Nuxt 4 con render en el servidor
    app/pages/              Nueve pantallas
    server/api/             El BFF: reenvía la sesión a la API
  deploy/                   Dockerfiles, compose, Caddy, roles y respaldos
  docs/                     Esta documentación
```

---

## Los cinco temas que aparecen en todos los documentos

**1. El aislamiento lo impone la base.** PostgreSQL con Row Level Security sobre
`tenant_id`, forzada, y una función que lanza excepción cuando no hay contexto de
estudio. Un error de programación produce un error ruidoso, no una lista vacía.
Ver [03](03-MULTITENANCY-Y-ROLES.md).

**2. Lo que no se verificó se declara como no verificado.** Cuatro cosas quedaron
pendientes a propósito: el plazo de entrega, el plazo del laboratorio, el plazo de
espera de la selección y los plazos de conservación de datos. **Ninguna tiene un
número inventado en un valor por omisión.** Ver
[05](05-CONFIGURACION.md) y [07](07-SEGURIDAD-Y-LEY-19628.md).

**3. El software no inventa reglas del negocio.** No hay precios por omisión: el
estudio los declara en sus paquetes y en cada encargo al laboratorio. No hay
formatos de impresión en el código: cada laboratorio ofrece los suyos. Ver
[05](05-CONFIGURACION.md).

**4. Nada se borra.** Las personas se desactivan, los contratos se anulan con
motivo, los medios con originales se marcan `destruida` y se dejan de usar. El
motivo es concreto: las fechas prometidas, los abonos y la custodia de los
originales son la evidencia con la que se responde un reclamo, y borrarlos deja
números que no cuadran. Ver [02](02-MODELO-DE-DATOS.md).

**5. Un saldo es un desglose, no un número.** "Cuánto entregué", "cuánto falta" y
"cuánto me deben" son tres preguntas distintas. El servicio de cobranza devuelve
ocho cifras y dos listas; la pantalla muestra las ocho. Ver
[02](02-MODELO-DE-DATOS.md) y [06](06-PRIMIER-CONTRATO.md).

---

## Una nota sobre el material de referencia

Esta aplicación se escribió siguiendo el patrón de aislamiento que usan las demás
aplicaciones de Hefesto Studio. Al revisar ese material aparecieron cuatro errores
fácticos que **no** se copiaron. Están enumerados uno por uno en
[10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md#errores-factuales-encontrados-en-el-material-de-referencia),
que es el lugar donde se cuentan las cosas que no se hicieron bien, en vez de
esconderlas en un comentario.

---

© 2026 Hefesto Studio. Software propietario.