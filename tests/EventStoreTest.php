<?php

declare(strict_types=1);

use PaymosWhmcs\InMemoryEventStore;

function test_whmcs_event_store_commits_only_after_success()
{
    $store = new InMemoryEventStore();

    assertTrueValue($store->remember('evt_1', 604800), 'first event remember must acquire a pending lock.');
    $store->release();
    assertTrueValue($store->remember('evt_1', 604800), 'released event must be retryable.');
    $store->commit();
    assertFalseValue($store->remember('evt_1', 604800), 'committed event must be treated as duplicate.');
}
