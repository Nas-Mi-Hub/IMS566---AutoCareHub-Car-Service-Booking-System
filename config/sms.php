<?php
/**
 * AutoCare Hub — SMS configuration (Malaysia-friendly)
 *
 * Drivers:
 *  - 'log'     : writes to storage/logs/sms.log (safe default for demos)
 *  - 'twilio'  : Twilio REST API
 *  - 'http'    : Generic HTTP GET/POST gateway (Msg91-style / local bulk SMS)
 *
 * Never commit real API secrets; use environment variables in production.
 */

return [
    'driver'  => getenv('ACH_SMS_DRIVER') ?: 'log',
    'enabled' => (getenv('ACH_SMS_ENABLED') ?: '0') === '1',
    'sender'  => getenv('ACH_SMS_SENDER') ?: 'AutoCare',

    'twilio' => [
        'account_sid' => getenv('ACH_TWILIO_SID') ?: '',
        'auth_token'  => getenv('ACH_TWILIO_TOKEN') ?: '',
        'from'        => getenv('ACH_TWILIO_FROM') ?: '', // e.g. +60123456789
    ],

    // Generic HTTP SMS gateway (customize URL template)
    'http' => [
        'url'    => getenv('ACH_SMS_HTTP_URL') ?: '',
        'method' => getenv('ACH_SMS_HTTP_METHOD') ?: 'POST',
        'api_key'=> getenv('ACH_SMS_API_KEY') ?: '',
    ],
];
