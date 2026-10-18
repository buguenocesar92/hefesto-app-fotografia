# 06 · Primer contrato

Del estudio vacío al primer paquete entregado y cobrado. Esta es la ruta que hace
un estudio la primera vez, y también la que revela si el sistema está bien.

---

## El recorrido

```
 1. El estudio entra                fotografia:crear-estudio
 2. Declara sus paquetes            administración
 3. Declara su laboratorio          administración
 4. Carga a la persona              clientes
 5. Vende un paquete                contratos + líneas + abono
 6. Agenda la toma                  agenda
 7. La toma se realiza              sesiones/realizada
 8. Se sube el inventario           fotos
 9. La persona elige                selección
10. Se entrega                     (lineas cerradas)
11. Se cobra el resto               abonos
12. Si hay impresión, se encarga    laboratorio
```

---

## 1. El estudio entra

```bash
php artisan fotografia:crear-estudio
```

Es la **única excepción controlada** al aislamiento. Sin sesión no hay contexto de
estudio, y sin contexto `app_tenant_id()` lanzaría excepción. El comando usa la
misma función, con el mismo alcance transaccional, y con el estudio que está
creando.

Lo normal es que lo haga una sola persona, una vez.

---

## 2 y 3. Paquetes y laboratorio

Las dos pantallas de administración.

Un paquete describe **lo que promete el estudio**: cuántas fotos, cuántas horas
de sesión, qué incluye. Es lo que la persona compra. El precio lo declara el
estudio; el software no tiene ninguno.

Un laboratorio es a quién se le encarga la impresión. El estudio **no vende papel**:
lo encarga. Esa diferencia cambia lo que se guarda:

| | Si el estudio vendiera papel | Si lo encarga (esto) |
|---|---|---|
| El formato | lo decide el estudio | **lo decide el laboratorio** |
| El precio del formato | catálogo propio | **el que negocie el estudio** |
| Dónde se produce | en el estudio | en otro lado |
| Qué se guarda | el producto | **el encargo y su seguimiento** |

Por eso `FOTOGRAFIA_FORMATOS_IMPRESION` nace vacío: una lista inventada produce
pedidos que el laboratorio no puede hacer.

---

## 4. Cargar a la persona

Ficha con nombre, apellido, RUT, teléfono, correo y notas.

El RUT tiene validación de formato, no de existencia. No hay conexión con el
registro de Chile, y no se iba a inventar una.

**Las notas son un lugar delicado y hay que tratarlo como tal.** Acá se escribe
lo que el estudio sabe de la persona. Es dato personal y lo es más todavía cuando
la persona fotografiada es menor de edad. Ver
[07](07-SEGURIDAD-Y-LEY-19628.md).

---

## 5. Vende un paquete

Tres cosas en una pantalla:

**El contrato.** Cliente, fecha, `precio_total`, y `dias_promesa_entrega`.

Ese plazo se escribe **por contrato**, no se toma de la configuración global. Es la
promesa que el estudio le hizo a **esta** persona en **esta** fecha. La
configuración global es solo el punto de partida.

**Las líneas.** Cada línea es una cosa prometida:

| Tipo | Qué es | `unidad_minutos` |
|---|---|---|
| `sesion` | una toma, con su duración | sí |
| `foto_entregada` | cuántas fotos tiene derecho a elegir | no |
| `impresion` | qué se imprime | no |
| `album` | un álbum | no |
| `archivo_digital` | los archivos sin impresión | no |
| `adicional` | lo que se pactó fuera del catálogo | no |

`unidad_minutos` es lo que traduce "sesión de dos horas" a la agenda. La duración
de una sesión **no sale de la configuración**: sale de la línea que la promete.

**El abono inicial.** El 50% por omisión. Con
`FOTOGRAFIA_EXIGIR_ABONO_INICIAL=true`, un contrato sin abono queda en `borrador`
y no ocupa agenda.

### El detalle que explica todo el sistema

El saldo se calcula sobre `contratos.precio_total`, **no sobre lo entregado**.

Si se calculara sobre lo entregado, en el momento de firmar no hay nada entregado,
el total sería cero y el abono inicial no sería una deuda: sería un adelanto sobre
nada. Con el total pactado, la firma ya genera una deuda real de la mitad, que es
lo que hace que el abono tenga sentido.

Por eso al firmar, el saldo pendiente es la mitad del contrato, y eso es lo
correcto.

---

## 6. Agenda la toma

Fecha prometida, título, tipo, lugar, y el fotógrafo si se quiere.

La fecha prometida de entrega se calcula con el `dias_promesa_entrega` del
contrato. **Si el plazo no está configurado, la pantalla lo dice** y la fecha
queda vacía. No hay número inventado. Ver [05](05-CONFIGURACION.md).

---

## 7. La toma se realiza

`POST /api/v1/sesiones/{id}/realizada`.

Es una ruta aparte y no un `PATCH` a propósito: es **un hecho nuevo**, con su
propia fecha. No es editar la sesión.

Al realizarla, el estado pasa a `pendiente_seleccion`.

---

## 8. Se sube el inventario

Nombre del archivo, extensión, bytes, ruta relativa.

Esto es el **inventario**, no el contenido. Dice cuántos archivos hay, cuánto
pesan y dónde se declaran que están. El binario vive en el medio declarado en
`conservaciones`.

**La aplicación no sube archivos.** `FotoArchivoController` devuelve la ficha del
original con su huella SHA-256, no el binario. No se pudo verificar cómo se montan
los medios declarados, así que la lectura real quedó sin implementar y declarada
como pendiente. Ver [08](08-RESPALDOS.md).

---

## 9. La persona elige

La pantalla más importante del sistema, porque es la que espera la persona.

Tres estados: `pendiente`, `en_curso`, `confirmada`.

Mientras está `en_curso` se puede cambiar. **Confirmada es el punto de no
retorno**: después se puede reimprimir, pero ya no se puede volver a elegir
distinto.

---

## 10. Se entrega

Y acá está la regla que le da sentido a todo lo anterior.

**No se marca entregada sin selección confirmada.**
`GestorDeEstadoDeSesion::exigirSeleccionConfirmada()` lo comprueba **en el
momento de escribir**, no solo al mostrar el botón. Entre que se carga la pantalla
y se aprieta el botón, la selección puede haber cambiado.

Si alguien intenta entregarla sin selección confirmada, se lanza
`DomainException` y la API responde **422** con un mensaje legible. Un 500 haría
pensar que la aplicación se cayó, cuando lo que pasó es que el sistema hizo lo que
debía.

La misma condición se expone como `motivo_de_no_poder_entregar`, para deshabilitar
el botón **sin dejarlo mudo**: el texto explica por qué.

---

## 11. Se cobra el resto

`POST /api/v1/saldos/{contrato}/abonos`.

Cada abono tiene fecha, monto, método y **referencia**. La referencia es lo que el
estudio escribe para ubicar el depósito después. Sin eso, un abono es un número
sin dueño.

---

## La entrega parcial, que es el caso que define todo

Se entregaron 80 de 100 fotos. ¿Qué pasa?

```
  líneas[].cantidad_entregada = 80
  líneas[].cantidad_pactada   = 100
  contrato.estado             = activo          <-- NO se cierra
  saldo pendiente             = el total menos lo cobrado
```

**Una entrega parcial nunca cierra el contrato.** El contrato sigue abierto
porque hay 20 fotos y un abono que no se ha cobrado. Cerrarlo ahí sería inventar un
final.

Lo que sí hace `RegistradorDeEntrega`:
- sube el `cantidad_entregada` de la línea;
- **rechaza** entregar más de lo pactado (error de dominio, 422);
- **rechaza** líneas de impresión o álbum sin selección confirmada;
- cierra el contrato **solo** cuando todas las líneas están completas.

---

## El saldo es un desglose, no un número

La pantalla de saldos muestra **ocho cifras**, no una. Porque "cuánto entregué",
"cuánto falta" y "cuánto me deben" son tres preguntas distintas:

- `precio_total` — lo pactado
- `total_entregado` — lo entregado
- `total_cobrado` — lo cobrado
- `saldo_pendiente` — lo que falta por cobrar
- `saldo_a_favor` — lo que se pagó de más
- Y las cuentas por línea y por abono

`saldo_pendiente` y `saldo_a_favor` son **ambos no negativos** (`max(0, ...)`).
Nunca un número con signo: un saldo en negativo en pantalla se lee como una deuda
del estudio, que es lo contrario de lo que significa.

La calculadora es **pura**: recibe estructuras planas, sin Eloquent y sin SQL. Por
eso su prueba es una unidad de verdad que corre sin PostgreSQL.
`ServicioDeSaldo` es el único que lee modelos y agrega las observaciones: sesiones
pendientes de selección y pedidos de laboratorio vencidos.

---

## Si hay impresión, se encarga

```
  borrador  -> enviado  -> en_laboratorio  -> recibido
                                              -> recibido_con_fallos
```

`recibido_con_fallos` es un estado de primera clase: pasa. Un laboratorio entrega
con faltantes y el sistema tiene que poder decirlo sin que el pedido parezca
completo.

El contenido del pedido **se congela al enviarlo**. Después hay un trabajo en curso
en otro lado, y cambiar el pedido no cambia lo que ya se imprimió.

---

© 2026 Hefesto Studio. Software propietario.