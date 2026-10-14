<?php

return [
    'mp_token' => env('MERCADOPAGO_ACCESS_TOKEN'), 'mp_webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
    'zip_token' => env('ZIPNOVA_API_TOKEN'), 'zip_secret' => env('ZIPNOVA_API_SECRET'), 'zip_account' => env('ZIPNOVA_ACCOUNT_ID'), 'zip_origin' => env('ZIPNOVA_ORIGIN_ID'),
    'cod_enabled' => (bool) env('COMMERCE_COD_ENABLED', false), 'cod_delivery_enabled' => (bool) env('COMMERCE_COD_DELIVERY_ENABLED', false),
    'arca_environment' => env('ARCA_ENVIRONMENT', 'homologation'), 'arca_cuit' => env('ARCA_CUIT'), 'arca_pos' => env('ARCA_POINT_OF_SALE'),
    'arca_condition' => env('ARCA_ISSUER_CONDITION', 'monotributo'), 'arca_name' => env('ARCA_ISSUER_NAME'), 'arca_address' => env('ARCA_ISSUER_ADDRESS'),
    'arca_iibb' => env('ARCA_IIBB'), 'arca_activity_date' => env('ARCA_ACTIVITY_DATE'), 'arca_certificate' => env('ARCA_CERTIFICATE_PATH'), 'arca_key' => env('ARCA_PRIVATE_KEY_PATH'),
];
