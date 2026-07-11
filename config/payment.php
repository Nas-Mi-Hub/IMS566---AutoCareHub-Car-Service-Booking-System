<?php
/**
 * AutoCare Hub — Optional payment gateway path (Malaysia)
 *
 * Manual proof upload remains the default (realistic for small bengkels).
 * Enable a gateway by setting ACH_GATEWAY_ENABLED=1 and filling credentials.
 *
 * Supported path stubs: billplz | senangpay | none
 */

return [
    'gateway_enabled' => (getenv('ACH_GATEWAY_ENABLED') ?: '0') === '1',
    'provider'        => getenv('ACH_GATEWAY_PROVIDER') ?: 'none', // billplz | senangpay | none

    'billplz' => [
        'api_key'    => getenv('ACH_BILLPLZ_API_KEY') ?: '',
        'collection' => getenv('ACH_BILLPLZ_COLLECTION') ?: '',
        'x_signature'=> getenv('ACH_BILLPLZ_XSIG') ?: '',
        'sandbox'    => (getenv('ACH_BILLPLZ_SANDBOX') ?: '1') === '1',
    ],

    'senangpay' => [
        'merchant_id' => getenv('ACH_SENANGPAY_MID') ?: '',
        'secret_key'  => getenv('ACH_SENANGPAY_SECRET') ?: '',
        'sandbox'     => (getenv('ACH_SENANGPAY_SANDBOX') ?: '1') === '1',
    ],

    // SST (Malaysia Sales & Service Tax) — applied on receipt display when enabled
    'sst_enabled' => (getenv('ACH_SST_ENABLED') ?: '0') === '1',
    'sst_percent' => (float) (getenv('ACH_SST_PERCENT') ?: 6),
];
