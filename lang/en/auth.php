<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | login screen
    |--------------------------------------------------------------------------
    */
    'login' => [
        'title' => 'Sign in',
        'social_area_label' => 'Sign in with a provider',
        'continue_with' => 'Continue with :provider',
        'or_password' => 'or sign in with your password',
        'email_label' => 'Email',
        'password_label' => 'Password',
        'remember_me' => 'Remember me',
        'submit' => 'Sign in',
        'forgot_password' => 'Forgot your password?',
        'create_account' => 'Create an account',
    ],

    /*
    |--------------------------------------------------------------------------
    | register screen
    |--------------------------------------------------------------------------
    */
    'register' => [
        'title' => 'Create your account',
        'username_label' => 'Username',
        'email_label' => 'Email',
        'password_label' => 'Password',
        'password_confirm_label' => 'Confirm password',
        'honeypot_label' => 'Leave this field empty',
        'submit' => 'Create account',
        'already_have_account' => 'Already have an account?',
        'sign_in_link' => 'Sign in',
    ],

    /*
    |--------------------------------------------------------------------------
    | forgot-password screen
    |--------------------------------------------------------------------------
    */
    'password' => [
        'forgot_title' => 'Reset your password',
        'forgot_intro' => "Enter your email and we'll send you a password-reset link.",
        'email_label' => 'Email',
        'forgot_submit' => 'Email reset link',
        'back_to_login' => 'Back to sign in',
    ],

    /*
    |--------------------------------------------------------------------------
    | reset-password screen
    |--------------------------------------------------------------------------
    */
    'reset' => [
        'title' => 'Choose a new password',
        'email_label' => 'Email',
        'new_password_label' => 'New password',
        'confirm_password_label' => 'Confirm new password',
        'submit' => 'Reset password',
    ],

    /*
    |--------------------------------------------------------------------------
    | two-factor-challenge screen
    |--------------------------------------------------------------------------
    */
    'two_factor' => [
        'title' => 'Two-factor authentication',
        'intro' => 'Enter the 6-digit code from your authenticator app. Lost your device? Use one of your recovery codes instead.',
        'code_label' => 'Authentication code',
        'or' => 'or',
        'recovery_code_label' => 'Recovery code',
        'submit' => 'Verify',
    ],

    /*
    |--------------------------------------------------------------------------
    | verify-email screen
    |--------------------------------------------------------------------------
    */
    'verify_email' => [
        'title' => 'Verify your email',
        'intro' => 'Thanks for registering! Please click the link in the email we just sent to finish setting up your account. If you didn\'t receive it, request another below.',
        'link_sent' => 'A fresh verification link has been sent to your email address.',
        'resend_button' => 'Resend verification email',
        'logout_button' => 'Log out',
    ],

    /*
    |--------------------------------------------------------------------------
    | confirm-password screen
    |--------------------------------------------------------------------------
    */
    'confirm_password' => [
        'title' => 'Confirm your password',
        'intro' => 'This is a secure area. Please confirm your password before continuing.',
        'label' => 'Password',
        'submit' => 'Confirm',
    ],

    /*
    |--------------------------------------------------------------------------
    | registration-closed screen
    |--------------------------------------------------------------------------
    */
    'registration_closed' => [
        'title' => 'Registration closed',
        'message' => 'New account registration is currently closed. Please check back later.',
        'back_button' => 'Back to sign in',
    ],

];
