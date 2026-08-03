<?php

declare(strict_types=1);

use PaymosWhmcs\Translation;

function test_whmcs_translation_uses_russian_client_language()
{
    $params = whmcs_gateway_params(array(
        'clientdetails' => array('language' => 'russian'),
    ));

    assertSameValue('Оплатить через Paymos', Translation::text('pay_button', $params), 'Russian client must receive Russian payment text.');
    assertSameValue('Paymos временно недоступен. Обратитесь в поддержку.', Translation::text('payment_unavailable', $params), 'Russian errors must use the Russian catalog.');
}

function test_whmcs_translation_falls_back_to_english()
{
    assertSameValue('Pay with Paymos', Translation::text('pay_button', whmcs_gateway_params()), 'Unknown or absent language must fall back to English.');
}
