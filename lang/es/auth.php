<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
| Spanish (es) — PROOF locale (P5.3). Human translation of lang/en/auth.php. `:provider` is a Laravel
| placeholder. Missing keys fall back to `en`.
*/

return [

    'login' => [
        'title' => 'Iniciar sesión',
        'social_area_label' => 'Iniciar sesión con un proveedor',
        'continue_with' => 'Continuar con :provider',
        'or_password' => 'o inicia sesión con tu contraseña',
        'email_label' => 'Correo electrónico',
        'password_label' => 'Contraseña',
        'remember_me' => 'Recordarme',
        'submit' => 'Iniciar sesión',
        'forgot_password' => '¿Olvidaste tu contraseña?',
        'create_account' => 'Crear una cuenta',
    ],

    'register' => [
        'title' => 'Crea tu cuenta',
        'username_label' => 'Nombre de usuario',
        'email_label' => 'Correo electrónico',
        'password_label' => 'Contraseña',
        'password_confirm_label' => 'Confirmar contraseña',
        'honeypot_label' => 'Deja este campo vacío',
        'submit' => 'Crear cuenta',
        'already_have_account' => '¿Ya tienes una cuenta?',
        'sign_in_link' => 'Iniciar sesión',
    ],

    'password' => [
        'forgot_title' => 'Restablece tu contraseña',
        'forgot_intro' => 'Introduce tu correo y te enviaremos un enlace para restablecer la contraseña.',
        'email_label' => 'Correo electrónico',
        'forgot_submit' => 'Enviar enlace de restablecimiento',
        'back_to_login' => 'Volver a iniciar sesión',
    ],

    'reset' => [
        'title' => 'Elige una nueva contraseña',
        'email_label' => 'Correo electrónico',
        'new_password_label' => 'Nueva contraseña',
        'confirm_password_label' => 'Confirmar nueva contraseña',
        'submit' => 'Restablecer contraseña',
    ],

    'two_factor' => [
        'title' => 'Autenticación en dos pasos',
        'intro' => 'Introduce el código de 6 dígitos de tu aplicación de autenticación. ¿Perdiste tu dispositivo? Usa uno de tus códigos de recuperación.',
        'code_label' => 'Código de autenticación',
        'or' => 'o',
        'recovery_code_label' => 'Código de recuperación',
        'submit' => 'Verificar',
    ],

    'verify_email' => [
        'title' => 'Verifica tu correo',
        'intro' => '¡Gracias por registrarte! Haz clic en el enlace del correo que acabamos de enviarte para terminar de configurar tu cuenta. Si no lo recibiste, solicita otro abajo.',
        'link_sent' => 'Se ha enviado un nuevo enlace de verificación a tu correo electrónico.',
        'resend_button' => 'Reenviar correo de verificación',
        'logout_button' => 'Cerrar sesión',
    ],

    'confirm_password' => [
        'title' => 'Confirma tu contraseña',
        'intro' => 'Esta es un área segura. Confirma tu contraseña antes de continuar.',
        'label' => 'Contraseña',
        'submit' => 'Confirmar',
    ],

    'registration_closed' => [
        'title' => 'Registro cerrado',
        'message' => 'El registro de nuevas cuentas está cerrado por ahora. Vuelve más tarde.',
        'back_button' => 'Volver a iniciar sesión',
    ],

];
