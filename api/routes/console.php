<?php

declare(strict_types=1);

/*
|-------------------------------------------------------------------------------
| Rutas de consola
|-------------------------------------------------------------------------------
|
| Los comandos de Fotografía se descubren automáticamente desde
| app/Console/Commands. Se dejan declarados aquí para que quede explícito que no
| hay comandos programados: las tareas periódicas que hacen falta (revisión de
| conservaciones, aviso de selecciones pendientes) son del despliegue, y deben
| declararse a mano. Ver docs/08.
|
| LOS QUE SÍ IMPORTAN
| --------------------
| `fotografia:verificar-aislamiento` comprueba, desde el servidor y sin pasar por
| la aplicación, que las dieciséis tablas de negocio tienen RLS forzado, que la
| vista pública expone solo tres columnas, que una consulta sin estudio falla, y
| que no se puede escribir una fila declarando el estudio de otro. Es el comando
| que hay que correr antes de recibir a la primera sesión. Ver docs/03.
|
| `fotografia:crear-estudio` da de alta un estudio con su primer administrador, y
| es la única excepción controlada al aislamiento. Ver docs/04.
|
| `fotografia:revisar-entregas` deja por escrito qué sesiones están esperando que
| la gente elija y de qué medios con originales no hay noticias. Es el que corre
| el dueño del estudio antes de abrir el panel. Ver docs/08.
|
*/