<?php

return array(
    'config_version' => 2,
    'environments' => array(
        'sandbox' => array(
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_test_REPLACE_WITH_YOUR_SANDBOX_KEY',
            'api_secret' => 'sk_test_REPLACE_WITH_YOUR_SANDBOX_SECRET',
            'project_id' => 'prj_REPLACE',
            'webhook_secret' => 'whsec_test_REPLACE_WITH_YOUR_SANDBOX_WEBHOOK',
        ),
        'live' => array(
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_live_REPLACE_WITH_YOUR_LIVE_KEY',
            'api_secret' => 'sk_live_REPLACE_WITH_YOUR_LIVE_SECRET',
            'project_id' => 'prj_REPLACE',
            'webhook_secret' => 'whsec_live_REPLACE_WITH_YOUR_LIVE_WEBHOOK',
        ),
    ),
);
