<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\Plugin\AesGcmEnvelope;
use Paymos\Plugin\CredentialSet;
use WHMCS\Database\Capsule;

final class CredentialStore
{
    private const CREDENTIALS_KEY = 'PaymosGatewayCredentialsV1';
    private const STATE_KEY = 'PaymosGatewayConnectStateV1';

    public static function loadCredentials()
    {
        $payload = self::load(self::CREDENTIALS_KEY, 'paymos-whmcs-credentials-v1');
        if (count($payload) === 0) {
            return array();
        }
        if (!isset($payload['schema'], $payload['environments'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['environments'])) {
            throw new \RuntimeException('Stored Paymos credentials have an invalid schema.');
        }
        return CredentialSet::normalize($payload['environments']);
    }

    public static function saveCredentials(array $environments)
    {
        self::save(self::CREDENTIALS_KEY, 'paymos-whmcs-credentials-v1', array(
            'schema' => 1,
            'environments' => CredentialSet::normalize($environments),
        ));
    }

    public static function saveState(array $state)
    {
        self::save(self::STATE_KEY, 'paymos-whmcs-connect-state-v1', array(
            'schema' => 1,
            'expires_at' => time() + (int) $state['expires_in'],
            'state' => $state,
        ));
    }

    public static function loadState()
    {
        $payload = self::load(self::STATE_KEY, 'paymos-whmcs-connect-state-v1');
        if (!isset($payload['schema'], $payload['expires_at'], $payload['state'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['state'])
            || time() >= (int) $payload['expires_at']) {
            self::clearState();
            return array();
        }
        return $payload['state'];
    }

    public static function clearState()
    {
        Capsule::table('tblconfiguration')->where('setting', self::STATE_KEY)->delete();
    }

    private static function load($setting, $aad)
    {
        $encoded = Capsule::table('tblconfiguration')->where('setting', $setting)->value('value');
        return !is_string($encoded) || $encoded === ''
            ? array()
            : AesGcmEnvelope::open($encoded, self::keyMaterial(), $aad);
    }

    private static function save($setting, $aad, array $payload)
    {
        $encoded = AesGcmEnvelope::seal($payload, self::keyMaterial(), $aad);
        Capsule::table('tblconfiguration')->updateOrInsert(
            array('setting' => $setting),
            array('value' => $encoded)
        );
    }

    private static function keyMaterial()
    {
        $material = isset($GLOBALS['cc_encryption_hash'])
            ? trim((string) $GLOBALS['cc_encryption_hash'])
            : '';
        if ($material === '') {
            throw new \RuntimeException('WHMCS encryption hash is not configured.');
        }
        return $material;
    }
}
