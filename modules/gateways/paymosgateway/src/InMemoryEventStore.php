<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\Webhook\CommitAwareEventStoreInterface;

final class InMemoryEventStore implements CommitAwareEventStoreInterface
{
    /** @var array<string, bool> */
    private $committed = array();

    /** @var string */
    private $pending = '';

    public function remember($eventId, $ttlSeconds)
    {
        $eventId = (string) $eventId;
        if (isset($this->committed[$eventId]) || $this->pending === $eventId) {
            return false;
        }

        $this->pending = $eventId;
        return true;
    }

    public function isCommitted($eventId)
    {
        return isset($this->committed[(string) $eventId]);
    }

    public function commit()
    {
        if ($this->pending === '') {
            return;
        }

        $this->committed[$this->pending] = true;
        $this->pending = '';
    }

    public function release()
    {
        $this->pending = '';
    }
}
