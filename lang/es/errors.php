<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
| Spanish (es) — PROOF locale (P5.3). Human translation of lang/en/errors.php. `:app` is a Laravel
| placeholder. Missing keys fall back to `en`.
*/

return [

    '403' => [
        'title' => 'Prohibido',
        'message' => 'No tienes permiso para ver esto. Si crees que es un error, contacta con un moderador.',
    ],

    '404' => [
        'title' => 'Página no encontrada',
        'message' => 'No pudimos encontrar esa página. Puede que se haya movido o que el enlace esté mal escrito.',
    ],

    '419' => [
        'title' => 'Página caducada',
        'message' => 'Tu sesión caducó por seguridad. Vuelve atrás, actualiza la página e inténtalo de nuevo.',
    ],

    '429' => [
        'title' => 'Espera un momento',
        'message' => 'Has hecho muchas solicitudes en poco tiempo. Espera un poco antes de volver a intentarlo.',
    ],

    '500' => [
        'title' => 'Algo salió mal',
        'message' => 'Ocurrió un error inesperado por nuestra parte. Lo hemos registrado; inténtalo de nuevo en un momento.',
    ],

    '503' => [
        'title' => 'Volvemos enseguida',
        'message' => 'El sitio está en mantenimiento breve. Gracias por tu paciencia; vuelve a intentarlo en breve.',
    ],

    'layout' => [
        'back_home' => 'Volver a :app',
    ],

];
