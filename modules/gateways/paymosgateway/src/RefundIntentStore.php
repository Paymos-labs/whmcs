<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundIntentPage;
use Paymos\Plugin\RefundIntentStoreInterface;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;

final class RefundIntentStore implements RefundIntentStoreInterface
{
    public const TABLE = 'mod_paymos_refund_intents';
    private $pdo;
    private $paymentKey;
    private $depth = 0;

    public function __construct($pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function database()
    {
        if ($this->pdo === null) {
            if (!class_exists('WHMCS\\Database\\Capsule')) {
                throw new \RuntimeException('refund_database_unavailable');
            }
            $this->pdo = \WHMCS\Database\Capsule::connection()->getPdo();
        }
        return $this->pdo;
    }

    public function install()
    {
        if ($this->database()->inTransaction()) {
            throw new \RuntimeException('refund_schema_transaction_conflict');
        }
        $this->execute('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            operation_id varchar(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            installation_id varchar(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            request_token varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            external_order_id varchar(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            native_payment_key varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            withdrawal_id varchar(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
            payload longtext NOT NULL, version bigint unsigned NOT NULL,
            created_at bigint unsigned NOT NULL, next_check bigint unsigned NOT NULL,
            UNIQUE KEY request_token (installation_id, request_token),
            UNIQUE KEY external_order_id (external_order_id), UNIQUE KEY withdrawal_id (withdrawal_id),
            KEY payment_key (native_payment_key), KEY due (next_check, operation_id)
        ) ENGINE=InnoDB');
        $status = $this->rows('SHOW TABLE STATUS WHERE Name = ?', array(self::TABLE));
        if (count($status) !== 1 || strcasecmp($status[0]['Engine'] ?? '', 'InnoDB') !== 0) {
            throw new \RuntimeException('refund_transactional_table_required');
        }
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public function installationId(): string
    {
        $key = 'PaymosRefundInstallationV1';
        $row = $this->rows('SELECT value FROM tblconfiguration WHERE setting = ?', array($key));
        if (!$row) {
            $this->execute('INSERT IGNORE INTO tblconfiguration (setting, value) VALUES (?, ?)', array($key, self::uuid()));
            $row = $this->rows('SELECT value FROM tblconfiguration WHERE setting = ?', array($key));
        }
        $id = $row ? $row[0]['value'] : '';
        if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/D', $id)) {
            throw new \RuntimeException('refund_installation_missing');
        }
        return $id;
    }

    public function withPaymentLock(string $paymentKey, callable $action)
    {
        if (!preg_match('/^[1-9][0-9]*$/D', $paymentKey)) {
            throw new \RuntimeException('refund_payment_key_invalid');
        }
        if ($this->paymentKey !== null) {
            if ($this->paymentKey !== $paymentKey) { throw new \RuntimeException('refund_lock_scope_conflict'); }
            $this->assertOwned();
            return $action();
        }
        $name = 'paymos-whmcs-invoice:' . $paymentKey;
        if ((int) $this->execute('SELECT GET_LOCK(?, 10)', array($name))->fetchColumn() !== 1) {
            throw new \Paymos\Exception\EventInProgressException('refund_payment_busy');
        }
        $this->paymentKey = $paymentKey;
        try {
            return $action();
        } finally {
            $this->paymentKey = null;
            $this->execute('SELECT RELEASE_LOCK(?)', array($name));
        }
    }

    public function assertOwned()
    {
        if ($this->paymentKey === null
            || (int) $this->execute('SELECT IS_USED_LOCK(?) = CONNECTION_ID()',
                array('paymos-whmcs-invoice:' . $this->paymentKey))->fetchColumn() !== 1) {
            throw new \RuntimeException('refund_payment_lock_lost');
        }
    }

    public function transaction(callable $action)
    {
        $this->assertOwned();
        if ($this->depth > 0) { return $action(); }
        if ($this->database()->inTransaction()) { throw new \RuntimeException('refund_foreign_transaction'); }
        $this->database()->beginTransaction();
        $this->depth = 1;
        try {
            $result = $action();
            $this->assertOwned();
            if (!$this->database()->commit()) { throw new \RuntimeException('refund_commit_unknown'); }
            return $result;
        } catch (\Throwable $error) {
            if ($this->database()->inTransaction()) { $this->database()->rollBack(); }
            throw $error;
        } finally {
            $this->depth = 0;
        }
    }

    /** WHMCS localAPI has its own commit boundary; the native transid is its marker. */
    public function outsideTransaction(callable $action)
    {
        $this->assertOwned();
        $resume = $this->depth > 0;
        if (!$resume && $this->database()->inTransaction()) { throw new \RuntimeException('refund_foreign_transaction'); }
        if ($resume) {
            if (!$this->database()->commit()) { throw new \RuntimeException('refund_commit_unknown'); }
            $this->depth = 0;
        }
        try {
            $result = $action();
            $this->assertOwned();
            return $result;
        } finally {
            if ($resume) {
                $this->assertOwned();
                $this->database()->beginTransaction();
                $this->depth = 1;
            }
        }
    }

    public function createIntent(RefundIntent $intent): RefundIntent
    {
        $this->assertIntentLock($intent);
        if ($this->depth !== 0) { throw new \RuntimeException('refund_intent_must_commit'); }
        return $this->transaction(function () use ($intent) {
            $data = $intent->toArray();
            $existing = $this->findByRequestToken($data['installation_id'], $data['request_token']);
            if ($existing !== null) {
                foreach (array('platform', 'native_payment_key', 'invoice_id', 'project_id', 'environment', 'mode', 'full_draft') as $field) {
                    if ($existing->toArray()[$field] !== $data[$field]) { throw new \RuntimeException('refund_request_conflict'); }
                }
                return $existing;
            }
            foreach ($this->forPayment($intent->paymentKey()) as $old) {
                if ($this->blocksNewIntent($old)) { throw new \RuntimeException('refund_payment_has_unresolved_intent'); }
            }
            $now = time();
            $data = array_replace($data, array('created_at' => $now, 'checks' => 0, 'version' => 1));
            $this->execute('INSERT INTO ' . self::TABLE . ' (operation_id, installation_id, request_token,
                external_order_id, native_payment_key, withdrawal_id, payload, version, created_at, next_check)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array($intent->operationId(), $data['installation_id'],
                $data['request_token'], $intent->externalOrderId(), $intent->paymentKey(), null,
                $this->encode($data), 1, $now, $now + 60));
            return new RefundIntent($data);
        });
    }

    public function blocksNewIntent(RefundIntent $intent): bool
    {
        return $intent->projectionState() === RefundStates::NATIVE_REVIEW
            || ($intent->mode() === RefundStates::FULL && ($intent->cashState() === RefundStates::COMPLETED
                || $intent->toArray()['projection'] !== null))
            || !in_array($intent->cashState(), array(RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED), true)
            || ($intent->cashState() === RefundStates::COMPLETED && $intent->projectionState() !== RefundStates::PROJECTION_APPLIED);
    }

    public function findByRequestToken(string $installationId, string $token): ?RefundIntent
    {
        return $this->one('installation_id = ? AND request_token = ?', array($installationId, $token));
    }

    public function getIntent(string $operationId): RefundIntent
    {
        $intent = $this->one('operation_id = ?', array($operationId));
        if ($intent === null) { throw new \RuntimeException('refund_intent_missing'); }
        return $intent;
    }

    public function findByExternalId(string $key): ?RefundIntent { return $this->one('external_order_id = ?', array($key)); }
    public function findByWithdrawalId(string $id): ?RefundIntent { return $this->one('withdrawal_id = ?', array($id)); }

    public function forPayment(string $paymentKey): array
    {
        return $this->intents('native_payment_key = ? ORDER BY created_at, operation_id', array($paymentKey));
    }

    public function bindWithdrawal(string $operationId, string $id): string
    {
        return $this->transaction(function () use ($operationId, $id) {
            $intent = $this->getIntent($operationId);
            $this->assertIntentLock($intent);
            $other = $this->findByWithdrawalId($id);
            if ($id === '' || ($other !== null && $other->operationId() !== $operationId)
                || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $id)) {
                $this->recordState($operationId, RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, 'refund_binding_conflict');
                return 'conflict';
            }
            if ($intent->withdrawalId() === $id) { return 'same'; }
            $this->save($intent, array('withdrawal_id' => $id));
            return 'bound';
        });
    }

    public function recordObservation(string $operationId, VerifiedRefund $snapshot): void
    {
        $this->transaction(function () use ($operationId, $snapshot) {
            $intent = $this->getIntent($operationId);
            $pin = $intent->observation();
            if ($intent->withdrawalId() !== $snapshot->withdrawalId() || $intent->invoiceId() !== $snapshot->invoiceId()
                || $intent->projectId() !== $snapshot->projectId() || $intent->environment() !== $snapshot->environment()
                || $intent->mode() !== $snapshot->mode() || $intent->externalOrderId() !== $snapshot->externalOrderId()
                || ($pin !== null && (!AmountGuard::amountsEqual($pin->principalDecimal(), $snapshot->principalDecimal())
                    || $pin->currency() !== $snapshot->currency() || $pin->network() !== $snapshot->network()
                    || !\Paymos\Plugin\RefundRecipientIdentity::same($pin, $snapshot)))) {
                throw new \RuntimeException('refund_observation_conflict');
            }
            $this->save($intent, array('observation' => $snapshot->toArray()));
        });
    }

    public function recordProjection(string $operationId, NativeProjectionResult $result): void
    {
        $this->transaction(function () use ($operationId, $result) {
            $intent = $this->getIntent($operationId);
            $draft = $intent->fullDraft();
            $snapshot = $intent->observation();
            $old = $intent->toArray()['projection'];
            if ($result->kind() !== NativeProjectionResult::EXACT_MATCH || $result->mode() !== $intent->mode()
                || $result->nativeId() === null || $result->nativeId() === '' || $snapshot === null || $snapshot->status() !== 'completed'
                || ($old !== null && $old !== $result->toArray())
                || ($draft !== null && ($result->nativeAmountMinor() !== $draft['amount_minor'] || $result->nativeCurrency() !== $draft['currency']))
                || ($draft !== null && ($result->cryptoObservationAmount() !== null || $result->cryptoAsset() !== null))
                || ($draft === null && ($result->nativeAmountMinor() !== null || $result->nativeCurrency() !== null
                    || !AmountGuard::amountsEqual($result->cryptoObservationAmount(), $snapshot->principalDecimal())
                    || $result->cryptoAsset() !== $snapshot->currency() . '/' . $snapshot->network()))) {
                throw new \RuntimeException('refund_projection_conflict');
            }
            $this->save($intent, array('projection' => $result->toArray()));
        });
    }

    public function recordState(string $operationId, string $cashState, string $projectionState, ?string $reason): void
    {
        if (!in_array($cashState, array('not_submitted', 'unknown', 'created', 'processing', 'completed', 'failed', 'cancelled', 'review'), true)
            || !in_array($projectionState, array('none', 'pending', 'applied', 'native_review'), true)) {
            throw new \InvalidArgumentException('refund_state_invalid');
        }
        $this->transaction(function () use ($operationId, $cashState, $projectionState, $reason) {
            $this->save($this->getIntent($operationId), array('cash_state' => $cashState,
                'projection_state' => $projectionState, 'review_reason' => $reason));
        });
    }

    public function claimEffect(string $operationId, string $kind, int $generation): EffectClaim
    {
        if ($kind === '' || $generation < 1 || $this->depth !== 0) { throw new \RuntimeException('refund_effect_must_commit'); }
        return $this->transaction(function () use ($operationId, $kind, $generation) {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (isset($effects[$key]) && $effects[$key] !== RefundStates::EFFECT_PENDING) { return new EffectClaim(EffectClaim::ALREADY_STARTED); }
            $effects[$key] = RefundStates::EFFECT_STARTED;
            $this->save($intent, array('effects' => $effects));
            return new EffectClaim(EffectClaim::CLAIMED);
        });
    }

    public function recordEffectOutcome(string $operationId, string $kind, int $generation, string $state): void
    {
        if ($kind === '' || $generation < 1 || !in_array($state, array('delivered', 'failed_no_effect', 'unknown', 'skipped'), true)) {
            throw new \InvalidArgumentException('refund_effect_invalid');
        }
        $this->transaction(function () use ($operationId, $kind, $generation, $state) {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (!isset($effects[$key]) || $effects[$key] !== RefundStates::EFFECT_STARTED) { throw new \RuntimeException('refund_effect_not_started'); }
            $effects[$key] = $state;
            $this->save($intent, array('effects' => $effects));
        });
    }

    public function listDue(\DateTimeImmutable $now, int $limit, ?string $cursor): RefundIntentPage
    {
        $limit = max(1, min(100, $limit));
        $items = $this->intents('next_check <= ? AND operation_id > ? ORDER BY operation_id LIMIT ' . $limit,
            array($now->getTimestamp(), $cursor ?: ''));
        return new RefundIntentPage($items, count($items) === $limit ? end($items)->operationId() : null);
    }

    public function defer(string $operationId, ?int $now = null): void
    {
        $now = $now === null ? time() : $now;
        $this->transaction(function () use ($operationId, $now) {
            $intent = $this->getIntent($operationId);
            $data = $intent->toArray();
            $historical = in_array($intent->cashState(), array('completed', 'failed', 'cancelled', 'review'), true);
            $changes = array('checks' => $data['checks'] + 1);
            if (!$historical && $now - $data['created_at'] > 86400) {
                $changes += array('projection_state' => RefundStates::NATIVE_REVIEW, 'review_reason' => 'refund_unresolved_over_24h');
                $historical = true;
            }
            $delays = array(60, 120, 300, 900, 3600);
            $this->save($intent, $changes, $now + ($historical ? 86400 : $delays[min($changes['checks'], 4)]));
        });
    }

    public function rows($sql, array $values = array()): array
    {
        return $this->execute($sql, $values)->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function execute($sql, array $values = array())
    {
        $statement = $this->database()->prepare($sql);
        if (!$statement || !$statement->execute($values)) { throw new \RuntimeException('refund_database_failed'); }
        return $statement;
    }

    private function one($where, array $values): ?RefundIntent
    {
        $rows = $this->intents($where, $values);
        if (count($rows) > 1) { throw new \RuntimeException('refund_identity_ambiguous'); }
        return $rows ? $rows[0] : null;
    }

    private function intents($where, array $values): array
    {
        return array_map(static function ($row) {
            $data = json_decode($row['payload'], true);
            if (!is_array($data)) { throw new \RuntimeException('refund_store_corrupt'); }
            return new RefundIntent($data);
        }, $this->rows('SELECT payload FROM ' . self::TABLE . ' WHERE ' . $where, $values));
    }

    private function assertIntentLock(RefundIntent $intent)
    {
        $this->assertOwned();
        if ($this->paymentKey !== $intent->paymentKey()) { throw new \RuntimeException('refund_lock_scope_conflict'); }
    }

    private function save(RefundIntent $intent, array $changes, $nextCheck = null)
    {
        $this->assertIntentLock($intent);
        $old = $intent->toArray();
        $data = array_replace($old, $changes, array('version' => $old['version'] + 1));
        $sql = 'UPDATE ' . self::TABLE . ' SET payload = ?, withdrawal_id = ?, version = ?';
        $values = array($this->encode($data), $data['withdrawal_id'], $data['version']);
        if ($nextCheck !== null) { $sql .= ', next_check = ?'; $values[] = $nextCheck; }
        $sql .= ' WHERE operation_id = ? AND version = ?';
        $values[] = $intent->operationId();
        $values[] = $old['version'];
        if ($this->execute($sql, $values)->rowCount() !== 1) { throw new \RuntimeException('refund_store_write_conflict'); }
    }

    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new \RuntimeException('refund_store_encoding_failed'); }
        return $json;
    }
}
