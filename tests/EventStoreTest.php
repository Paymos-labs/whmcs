<?php

declare(strict_types=1);

// A WHMCS-shaped Capsule the DB-backed EventStore branches on. Once this file
// is loaded the class exists for the whole run, so the store's Capsule path —
// including the A10-02 owner-token fencing — runs against the fake table state
// below instead of falling back to the in-memory store.
namespace WHMCS\Database {

    if (!class_exists('WHMCS\Database\Capsule', false)) {
        final class Capsule
        {
            public static function table($name)
            {
                return new \FakeWhmcsCapsuleQuery((string) $name);
            }
        }
    }
}

namespace {

    use PaymosWhmcs\InMemoryEventStore;

    /**
     * Rows per table, keyed by primary key (event_id for the events table).
     * Reset between tests by paymos_whmcs_reset_test_state().
     */
    final class FakeWhmcsCapsuleState
    {
        /** @var array<string, array<string, array<string, mixed>>> */
        public static $tables = array();

        public static function reset()
        {
            self::$tables = array();
        }
    }

    /**
     * The slice of the Capsule query builder EventStore uses: chained where()
     * calls (two-arg equality and three-arg comparison), first(), insert(),
     * update() returning affected rows, delete() returning affected rows.
     */
    final class FakeWhmcsCapsuleQuery
    {
        /** @var string */
        private $table;

        /** @var array<int, array{0: string, 1: string, 2: mixed}> */
        private $wheres = array();

        public function __construct($table)
        {
            $this->table = (string) $table;
        }

        public function where($column, $operator, $value = null)
        {
            if (func_num_args() === 2) {
                $this->wheres[] = array((string) $column, '=', $operator);
            } else {
                $this->wheres[] = array((string) $column, (string) $operator, $value);
            }

            return $this;
        }

        /**
         * @param array<string, mixed> $row
         */
        private function matches(array $row)
        {
            foreach ($this->wheres as $condition) {
                list($column, $operator, $value) = $condition;
                $actual = isset($row[$column]) ? $row[$column] : null;
                switch ($operator) {
                    case '=':
                        if ((string) $actual !== (string) $value) {
                            return false;
                        }
                        break;
                    case '<':
                        if (!(is_numeric($actual) && (int) $actual < (int) $value)) {
                            return false;
                        }
                        break;
                    default:
                        return false;
                }
            }

            return true;
        }

        public function first()
        {
            foreach (FakeWhmcsCapsuleState::$tables[$this->table] ?? array() as $row) {
                if ($this->matches($row)) {
                    return (object) $row;
                }
            }

            return null;
        }

        public function insert(array $data)
        {
            if (isset($data['event_id'])
                && isset(FakeWhmcsCapsuleState::$tables[$this->table][$data['event_id']])) {
                // MySQL would reject the duplicate PRIMARY KEY; the store
                // catches it and answers "already seen".
                throw new RuntimeException('Duplicate entry for key PRIMARY: ' . $data['event_id']);
            }

            if (isset($data['event_id'])) {
                FakeWhmcsCapsuleState::$tables[$this->table][$data['event_id']] = $data;

                return true;
            }

            FakeWhmcsCapsuleState::$tables[$this->table][] = $data;

            return true;
        }

        public function update(array $data)
        {
            $affected = 0;
            foreach (FakeWhmcsCapsuleState::$tables[$this->table] ?? array() as $key => $row) {
                if ($this->matches($row)) {
                    FakeWhmcsCapsuleState::$tables[$this->table][$key] = array_merge($row, $data);
                    $affected++;
                }
            }

            return $affected;
        }

        public function delete()
        {
            $affected = 0;
            foreach (FakeWhmcsCapsuleState::$tables[$this->table] ?? array() as $key => $row) {
                if ($this->matches($row)) {
                    unset(FakeWhmcsCapsuleState::$tables[$this->table][$key]);
                    $affected++;
                }
            }

            return $affected;
        }
    }

    function test_whmcs_event_store_commits_only_after_success()
    {
        $store = new InMemoryEventStore();

        assertTrueValue($store->remember('evt_1', 604800), 'first event remember must acquire a pending lock.');
        $store->release();
        assertTrueValue($store->remember('evt_1', 604800), 'released event must be retryable.');
        assertTrueValue($store->commit(), 'an in-memory commit of a pending reservation succeeds.');
        assertFalseValue($store->remember('evt_1', 604800), 'committed event must be treated as duplicate.');
    }

    function test_whmcs_db_event_store_tells_a_locked_event_from_a_committed_one()
    {
        // BUG-103: remember() says "seen" for both; isCommitted() must not.
        FakeWhmcsCapsuleState::reset();
        $first = new PaymosWhmcs\EventStore();
        assertTrueValue($first->remember('evt_db', 604800), 'first delivery takes the lock.');

        $retry = new PaymosWhmcs\EventStore();
        assertFalseValue($retry->remember('evt_db', 604800), 'a retry while the lock is held is not new.');
        assertFalseValue($retry->isCommitted('evt_db'), 'a locked, uncommitted event is not committed.');

        assertTrueValue($first->commit(), 'the owner commits its own reservation.');
        assertTrueValue($retry->isCommitted('evt_db'), 'after commit the event is committed.');
    }

    /**
     * A10-02: a delivery whose reservation expired must not delete the
     * successor's row, and while the successor is still busy a third delivery
     * must be rejected as in-flight until the successor commits.
     */
    function test_whmcs_stale_owner_release_does_not_touch_the_successor()
    {
        FakeWhmcsCapsuleState::reset();
        $events = 'mod_paymos_events';
        $ownerA = new PaymosWhmcs\EventStore();
        assertTrueValue($ownerA->remember('evt_stale_release', 604800), 'delivery A reserves the event.');

        // Delivery A is stalled past its 300s reservation: rewind the row's
        // expiry so the next remember() prunes it and takes the id over.
        FakeWhmcsCapsuleState::$tables[$events]['evt_stale_release']['expires_at'] = time() - 1;

        $ownerB = new PaymosWhmcs\EventStore();
        assertTrueValue($ownerB->remember('evt_stale_release', 604800), 'delivery B takes over the expired reservation.');
        $tokenB = (string) FakeWhmcsCapsuleState::$tables[$events]['evt_stale_release']['owner_token'];
        assertTrueValue($tokenB !== '', 'the successor row must carry its own owner token.');

        // A wakes up and fails: its release() must complete silently and leave B's row.
        $ownerA->release();
        assertTrueValue(isset(FakeWhmcsCapsuleState::$tables[$events]['evt_stale_release']), 'a stale release must not delete the successor reservation.');
        assertSameValue($tokenB, (string) FakeWhmcsCapsuleState::$tables[$events]['evt_stale_release']['owner_token'], 'the successor row must keep its own token.');

        // While B is busy, a third delivery is rejected as in-flight, not duplicate.
        $ownerC = new PaymosWhmcs\EventStore();
        assertFalseValue($ownerC->remember('evt_stale_release', 604800), 'a third delivery while B is busy must lose the race.');
        assertFalseValue($ownerC->isCommitted('evt_stale_release'), 'the event is not committed while B is still processing.');

        assertTrueValue($ownerB->commit(), 'the successor commits its own reservation.');
        assertTrueValue($ownerC->isCommitted('evt_stale_release'), 'after B commits the event is a duplicate.');
    }

    /**
     * A10-02: a stale delivery's commit() must report lost ownership (false)
     * and must not extend the successor's short reservation into a committed
     * one.
     */
    function test_whmcs_stale_owner_commit_does_not_confirm_the_successor()
    {
        FakeWhmcsCapsuleState::reset();
        $events = 'mod_paymos_events';
        $ownerA = new PaymosWhmcs\EventStore();
        assertTrueValue($ownerA->remember('evt_stale_commit', 604800), 'delivery A reserves the event.');

        FakeWhmcsCapsuleState::$tables[$events]['evt_stale_commit']['expires_at'] = time() - 1;

        $ownerB = new PaymosWhmcs\EventStore();
        assertTrueValue($ownerB->remember('evt_stale_commit', 604800), 'delivery B takes over the expired reservation.');
        $reservedByB = (int) FakeWhmcsCapsuleState::$tables[$events]['evt_stale_commit']['expires_at'];

        assertFalseValue($ownerA->commit(), 'a stale commit must report lost ownership, not success.');
        assertSameValue($reservedByB, (int) FakeWhmcsCapsuleState::$tables[$events]['evt_stale_commit']['expires_at'], "a stale commit must not extend the successor's reservation.");
        assertFalseValue($ownerB->isCommitted('evt_stale_commit'), "B's row must still look busy, not committed.");

        $ownerC = new PaymosWhmcs\EventStore();
        assertFalseValue($ownerC->remember('evt_stale_commit', 604800), 'C is still rejected while B is busy.');

        assertTrueValue($ownerB->commit(), 'the successor commits its own reservation.');
        assertTrueValue($ownerC->isCommitted('evt_stale_commit'), 'after B commits the event is a duplicate.');
    }

    /**
     * A10-02: owner tokens come from random_bytes, not the clock — two
     * reservations taken within the same second can never share a token.
     */
    function test_whmcs_owner_tokens_do_not_collide_within_the_same_second()
    {
        FakeWhmcsCapsuleState::reset();
        $events = 'mod_paymos_events';
        $first = new PaymosWhmcs\EventStore();
        $second = new PaymosWhmcs\EventStore();

        assertTrueValue($first->remember('evt_token_a', 604800), 'first reservation.');
        assertTrueValue($second->remember('evt_token_b', 604800), 'second reservation in the same second.');

        $tokenA = (string) FakeWhmcsCapsuleState::$tables[$events]['evt_token_a']['owner_token'];
        $tokenB = (string) FakeWhmcsCapsuleState::$tables[$events]['evt_token_b']['owner_token'];

        assertTrueValue((bool) preg_match('/^[0-9a-f]{32}$/', $tokenA), 'owner token must be 16 random bytes as hex.');
        assertTrueValue((bool) preg_match('/^[0-9a-f]{32}$/', $tokenB), 'owner token must be 16 random bytes as hex.');
        assertTrueValue($tokenA !== $tokenB, 'two reservations in the same second must not share an owner token.');
        assertSameValue(
            (int) FakeWhmcsCapsuleState::$tables[$events]['evt_token_a']['created_at'],
            (int) FakeWhmcsCapsuleState::$tables[$events]['evt_token_b']['created_at'],
            'precondition: both rows were written in the same second.'
        );
    }

    /**
     * A10-02: commit() with nothing pending, or against a row taken over by a
     * successor, is a quiet non-success (zero affected rows), never an error.
     */
    function test_whmcs_zero_row_commit_is_reported_as_not_owned()
    {
        FakeWhmcsCapsuleState::reset();
        $events = 'mod_paymos_events';
        $store = new PaymosWhmcs\EventStore();

        assertFalseValue($store->commit(), 'commit without a pending reservation must report non-success.');

        assertTrueValue($store->remember('evt_zero_rows', 604800), 'reserve before losing the row.');
        FakeWhmcsCapsuleState::$tables[$events]['evt_zero_rows']['expires_at'] = time() - 1;
        $successor = new PaymosWhmcs\EventStore();
        assertTrueValue($successor->remember('evt_zero_rows', 604800), 'the successor takes the expired id over.');
        assertFalseValue($store->commit(), 'a commit that matched zero rows must report lost ownership.');
        assertTrueValue(isset(FakeWhmcsCapsuleState::$tables[$events]['evt_zero_rows']), 'the failed commit must leave the successor row in place.');
    }
}
