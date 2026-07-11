<?php
/**
 * AutoCare Hub — Email configuration
 * Supports native PHP mail() or PHPMailer (if installed via Composer).
 *
 * Optional: set environment variables or edit values below for production.
 */

return [
    // driver: 'mail' (PHP mail) | 'phpmailer' (SMTP via PHPMailer)
    'driver'     => getenv('ACH_MAIL_DRIVER') ?: 'mail',
    'from_email' => getenv('ACH_MAIL_FROM') ?: 'support@autocarehub.my',
    'from_name'  => getenv('ACH_MAIL_FROM_NAME') ?: 'AutoCare Hub',

    // SMTP settings (used when driver = phpmailer)
    'smtp' => [
        'host'       => getenv('ACH_SMTP_HOST') ?: 'smtp.gmail.com',
        'port'       => (int) (getenv('ACH_SMTP_PORT') ?: 587),
        'username'   => getenv('ACH_SMTP_USER') ?: '',
        'password'   => getenv('ACH_SMTP_PASS') ?: '',
        'encryption' => getenv('ACH_SMTP_ENCRYPTION') ?: 'tls', // tls | ssl | ''
    ],
];
