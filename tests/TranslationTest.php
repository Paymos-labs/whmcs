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

// The plugin ships six catalogues. Four of them were unreachable: the resolver
// only ever answered "russian" or "english", so a German store read the English
// file and the other three .php files were dead weight on disk.
function test_whmcs_translation_reaches_every_shipped_catalog()
{
    $expected = array(
        'german' => 'Mit Paymos bezahlen',
        'spanish' => 'Pagar con Paymos',
        'turkish' => 'Paymos ile öde',
        'chinese' => '通过 Paymos 付款',
        'russian' => 'Оплатить через Paymos',
        'english' => 'Pay with Paymos',
    );

    foreach ($expected as $language => $button) {
        $params = whmcs_gateway_params(array('clientdetails' => array('language' => $language)));
        assertSameValue($button, Translation::text('pay_button', $params), $language . ' client must read the ' . $language . ' catalog.');
    }
}

// WHMCS reports the language by its English name, but a client record can carry
// an ISO code instead depending on where it came from.
function test_whmcs_translation_accepts_iso_codes()
{
    $codes = array(
        'de' => 'Mit Paymos bezahlen',
        'de-DE' => 'Mit Paymos bezahlen',
        'es' => 'Pagar con Paymos',
        'tr_TR' => 'Paymos ile öde',
        'zh-Hans' => '通过 Paymos 付款',
        'ru' => 'Оплатить через Paymos',
    );

    foreach ($codes as $code => $button) {
        $params = whmcs_gateway_params(array('clientdetails' => array('language' => $code)));
        assertSameValue($button, Translation::text('pay_button', $params), $code . ' must resolve to its catalog.');
    }
}

// A language we ship no catalogue for must read English, not a raw key.
function test_whmcs_translation_unknown_language_reads_english()
{
    $params = whmcs_gateway_params(array('clientdetails' => array('language' => 'portuguese')));
    assertSameValue('Pay with Paymos', Translation::text('pay_button', $params), 'An unshipped language must fall back to English.');
}

// The gateway decides whether to surface the API's English error detail. That
// decision used to be made by comparing a button label against its English text,
// which couples an unrelated string to error handling.
function test_whmcs_translation_reports_whether_the_store_reads_english()
{
    assertSameValue(true, Translation::isEnglish(whmcs_gateway_params()), 'A store with no language set reads English.');
    assertSameValue(
        false,
        Translation::isEnglish(whmcs_gateway_params(array('clientdetails' => array('language' => 'german')))),
        'A German store does not read English.');
}
