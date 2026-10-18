<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Reglas de Fotografía
|------------------------------------------------------------------------------
|
| Lo que la aplicación necesita saber del estudio y que no cabe en el código.
| Cada clave está justificada al lado. Ver docs/05.
|
| LA REGLA DE ESTE ARCHIVO
| -------------------------
| Un número puesto aquí es una promesa que el software le está haciendo a un
| cliente sin que nadie la haya acordado. Por eso:
|
|   - Los PRECIOS no están. Los declara cada estudio en sus paquetes.
|   - Los PLAZOS COMERCIALES no están. El plazo de entrega y el plazo de
|     laboratorio son promesas del estudio, no reglas técnicas.
|   - Lo que SÍ tiene valor por omisión es lo que es una restricción técnica
|     o una decisión de configuración de la instalación, y se explica por qué.
|
| Cuando el valor por omisión es `null`, la pantalla lo DECLARA. Un plazo sin
| configurar que se ve como configured es peor que uno que se ve pendiente.
|
*/

$enteroONulo = static function (?string $valor): ?int {
    if ($valor === null) {
        return null;
    }

    $valor = trim($valor);

    return $valor === '' ? null : (int) $valor;
};

/**
 * Una lista de texto separada por comas, o `null` si está vacía.
 *
 * Se usa para los formatos de impresión. Una lista vacía es información: el
 * estudio no ha declarado formatos y la pantalla lo dice, en vez de mostrar una
 * lista que se inventó el software.
 *
 * @return list<string>|null
 */
$listaONula = static function (?string $valor): ?array {
    if ($valor === null || trim($valor) === '') {
        return null;
    }

    $partes = array_values(array_filter(
        array_map('trim', explode(',', $valor)),
        static fn (string $parte): bool => $parte !== ''
    ));

    return $partes === [] ? null : $partes;
};

return [

    /*
    |----------------------------------------------------------------------
    | Agenda de sesiones
    |----------------------------------------------------------------------
    |
    | Estos dos SÍ tienen valor por omisión, porque son límites técnicos de la
    | agenda y no promesas comerciales: una sesión de veinte horas o una sesión
    | agendada para dentro de tres años casi siempre son un error de tipeo.
    |
    | La duración de una sesión NO sale de acá: sale de la línea del contrato que
    | la promete. Un paquete de "sesión de dos horas" se traduce en una línea de
    | dos horas y de ahí a la agenda. Ver docs/02.
    |
    */
    'agenda' => [
        'duracion_maxima_minutos' => (int) env('FOTOGRAFIA_DURACION_MAXIMA_MINUTOS', 600),

        // Cuántos días hacia adelante se puede agendar. Es el límite del widget
        // de fechas, no una promesa: el furthest que se puede agendar no es lo
        // mismo que la fecha que se promete entregar.
        'anticipacion_maxima_dias' => (int) env('FOTOGRAFIA_ANTICIPACION_MAXIMA_DIAS', 540),

        // Cuántos días antes, como mínimo, hay que agendar. Cero significa que se
        // acepta agendar para hoy, que es lo normal en un estudio chico que
        // tiene huecos.
        'anticipacion_minima_dias' => (int) env('FOTOGRAFIA_ANTICIPACION_MINIMA_DIAS', 0),
    ],

    /*
    |----------------------------------------------------------------------
    | Fecha prometida de entrega
    |----------------------------------------------------------------------
    |
    | NO HAY VALOR POR OMISIÓN. El plazo entre la sesión y la entrega de las
    | fotos es una promesa comercial: un paquete de bautismo y uno de portraits
    | de empresa no tienen el mismo plazo, y un estudio que promete rápido cobra
    | distinto. No se pudo verificar un número que sirva para todo el rubro.
    |
    | Con `null`, la pantalla de agenda muestra la fecha de la sesión y dice que
    | el plazo de entrega NO está configurado. Ver docs/06.
    |
    */
    'entrega' => [
        'dias_promesa_por_omision' => $enteroONulo(env('FOTOGRAFIA_DIAS_PROMESA_ENTREGA')),
    ],

    /*
    |----------------------------------------------------------------------
    | Impresión: SUBCONTRATO, NO VENTA DE PAPEL
    |----------------------------------------------------------------------
    |
    | El estudio no vende papel: ENCARGA la impresión a un laboratorio externo.
    | Eso cambia lo que esta configuración puede y no puede tener.
    |
    | NO HAY FORMATOS CON PRECIO. El precio de una impresión lo negocia el
    | estudio con su laboratorio, cambia por temporada y por cantidad, y no hay
    | forma de verificarlo desde acá. Lo que hay es:
    |
    |   - `formatos`: lo que el sistema ACEPTA en un pedido. Vacío a propósito:
    |     cada estudio trabaja con los formatos que su laboratorio ofrece.
    |   - `dias_promesa_laboratorio_dias`: el plazo que el estudio promete por
    |     omisión al encargar. Vacío a propósito, por lo mismo que arriba.
    |
    | Un pedido a laboratorio puede además llevar el plazo que se le pactó a ESA
    | fecha, en el propio pedido. El valor de acá es solo el punto de partida.
    |
    */
    'impresion' => [
        'formatos' => $listaONula(env('FOTOGRAFIA_FORMATOS_IMPRESION')),
        'dias_promesa_laboratorio_dias' => $enteroONulo(env('FOTOGRAFIA_DIAS_PROMESA_LABORATORIO')),
    ],

    /*
    |----------------------------------------------------------------------
    | Abonos
    |----------------------------------------------------------------------
    |
    | El cincuenta por ciento al contratar y el resto al entregar es la forma en
    | que se describió el ciclo de caja de este rubro, así que es el valor por
    | omisión. NO ES UN PRECIO NI UN PLAZO LEGAL: es la política de la
    | instalación, y se cambia acá cuando el estudio cambie la suya.
    |
    | `porcentaje_abono_inicial` NO es un porcentaje de comisión ni una comisión
    | implícita: es solo la parte del contrato que se cobra al firmar.
    |
    | El saldo nunca se deduce de acá. El saldo lo calcula
    | App\Services\Cobranza\CalculadoraDeSaldo, que devuelve el desglose. Ver
    | docs/02.
    |
    */
    'abonos' => [
        'porcentaje_abono_inicial' => (int) env('FOTOGRAFIA_PORCENTAJE_ABONO_INICIAL', 50),

        // Si el abono inicial es obligatorio para que el contrato pase a activo.
        // Con `true`, un contrato sin abono inicial queda en `borrador` y no
        // ocupa agenda.
        'exigir_abono_inicial' => (bool) env('FOTOGRAFIA_EXIGIR_ABONO_INICIAL', true),

        // Si se admite un abono que no cubre lo pactado. En la práctica siempre
        // se admite (la caja manda), pero se declara para que la pantalla diga
        // lo que el sistema permite.
        'permitir_abono_parcial' => (bool) env('FOTOGRAFIA_PERMITIR_ABONO_PARCIAL', true),
    ],

    /*
    |----------------------------------------------------------------------
    | Selección de fotos
    |----------------------------------------------------------------------
    |
    | "Pendiente de selección" NO es un error: es el estado normal de una
    | sesión que se realizó y todavía no se eligió. El sistema lo trata como un
    | estado de trabajo y no como una falla. Ver docs/03.
    |
    | NO HAY PLAZO POR OMISIÓN. Cuántos días se espera para que la persona
    | elija depende del tipo de sesión y no se pudo verificar un número para el
    | rubro. Con `null` el sistema NO avisa a nadie por sí solo, y la sesión
    | queda visible en la lista de pendientes con su fecha.
    |
    */
    'seleccion' => [
        'dias_para_seleccionar' => $enteroONulo(env('FOTOGRAFIA_DIAS_PARA_SELECCION')),

        // Cuántas fotos se pueden marcar por encima de las que el paquete
        // promete. Con `null` no se bloquea y no se avisa: la decisión de qué
        // pasa con las fotos de más es del estudio, no del software.
        'exceso_seleccion_sin_pago' => $enteroONulo(env('FOTOGRAFIA_EXCESO_SELECCION_SIN_PAGO')),
    ],

    /*
    |----------------------------------------------------------------------
    | CONSERVACIÓN: LA PARTE QUE NO SE PUEDE HACER CON UN TEXTO LIBRE
    |----------------------------------------------------------------------
    |
    | Un estudio entrega las fotos "de por vida". Eso obliga a responder dos
    | preguntas que un campo de texto libre no responde nunca:
    |
    |   1. ¿Dónde están los originales de esta sesión?
    |   2. ¿Ese medio sigue vivo?
    |
    | Por eso hay una tabla `conservaciones` con un estado propio, y no una
    | columna `ruta_del_disco` en la sesión. Un texto libre no se puede vigilar,
    | no se puede alertar y no se puede auditar. Ver docs/02 y docs/08.
    |
    */
    'conservacion' => [
        /*
        | Cada cuántos días se verifica una conservación. Es una decisión
        | operativa del estudio y no una regla legal, por eso sí tiene valor por
        | omisión: un mes es la unidad con la que un técnico de medios revisa un
        | disco.
        */
        'dias_entre_verificaciones' => (int) env('FOTOGRAFIA_DIAS_ENTRE_VERIFICACIONES', 30),

        /*
        | DÍAS sin verificar antes de que el sistema avise. NO HAY VALOR POR
        | OMISIÓN: no se pudo verificar un número para el rubro, y un número
        | inventado produce avisos falsos que entrenan al equipo a ignorar el
        | sistema. Con `null` no hay aviso automático y la pantalla muestra la
        | fecha de la última verificación. Ver docs/08.
        */
        'dias_max_sin_verificar' => $enteroONulo(env('FOTOGRAFIA_DIAS_MAX_SIN_VERIFICAR')),
    ],

    /*
    |----------------------------------------------------------------------
    | PURGA DE DATOS: ESTA DECISIÓN ES DEL CLIENTE, NO DEL SOFTWARE
    |----------------------------------------------------------------------
    |
    | La Ley 19.628 obliga a que la conservación de datos personales tenga una
    | finalidad declarada, pero NO se pudo verificar en el entorno donde se
    | escribió esta aplicación el plazo que corresponde a la bitácora de accesos
    | a los archivos originales. Ver docs/07.
    |
    | Por eso el valor por omisión es `null`, y `null` significa:
    |
    |   "NO HAY PLAZO CONFIGURADO". La aplicación NO purga nada por su cuenta.
    |
    | Nótese lo que NO se purga nunca, en ningún caso: los originales de las
    | fotos. El estudio los entrega de por vida y no son un registro que se
    | pueda eliminar por un plazo. Su conservación va en `conservacion`, no acá.
    |
    | COMPLETAR solo cuando el estudio cliente, con su revisión legal, tenga un
    | plazo escrito. El valor va en DÍAS.
    */
    'conservacion_de_datos' => [
        'accesos_archivo_dias' => $enteroONulo(env('FOTOGRAFIA_RETENCION_ACCESOS_ARCHIVO_DIAS')),
        'avisar_antes_de_purgar' => (bool) env('FOTOGRAFIA_AVISAR_ANTES_DE_PURGAR', false),
    ],

];