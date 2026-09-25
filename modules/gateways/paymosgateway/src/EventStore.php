<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\Webhook\CommitAwareEventStoreInterface;

final class EventStore implements CommitAwareEventStoreInterface
{
    /**
     * Lifetime of the in-flight lock remember() takes. commit() extends the row
     * to the full dedup window, so a row that outlives its reservation was
     * committed (see isCommitted()).
     */
    private const RESERVATION_SECONDS = 300;

    /** @var InMemoryEventStore|null */
    private $fallback;

    /** @var string */
    private $pendingEventId = '';

    /** @var int */
    private $pendingTtlSeconds = 0;

    public function remember($eventId, $ttlSeconds)
    {
        if (!$this->hasCapsule()) {
            return $this->fallback()->remember($eventId, $ttlSeconds);
        }

        $eventId = (string) $eventId;
        $now = time();
        \WHMCS\Database\Capsule::table(Migrations::EVENTS_TABLE)
            ->where('expires_at', '<', $now)
            ->delete();

        $exists = \WHMCS\Database\Capsule::table(Migrations::EVENTS_TABLE)
            ->where('event_id', $eventId)
            ->first();
        if ($exists) {
            return false;
        }

        try {
            \WHMCS\Database\Capsule::table(Migrations::EVENTS_TABLE)->insert(array(
                'event_id' => $eventId,
                'expires_at' => $now + self::RESERVATION_SECONDS,
                'created_at' => $now,
            ));
        } catch (\Exception $e) {
            return false;
        }

        $this->pendingEventId = $eventId;
        $this->pendingTtlSeconds = (int) $ttlSeconds;

        return true;
    }

    /**
     * Whether the event was processed and committed — as opposed to merely
     * locked by a delivery that has not finished (BUG-103: that one must be
     * answered non-2xx, or a retry arriving mid-processing marks it delivered).
     * A committed row lives past its reservation; a lock does not.
     */
    public function isCommitted($eventId)
    {
        if (!$this->hasCapsule()) {
            return $this->fallback()->isCommitted($eventId);
        }

        $row = \WHMCS\Database\Capsule::table(Migrations::EVENTS_TABLE)
            ->where('event_id', (string) $eventId)
            ->first();
        if (!$row) {
            return false;
        }

        $expiresAt = isset($row->expires_at) ? (int) $row->expires_at : 0;
        $createdAt = isset($row->created_at) ? (int) $row->created_at : 0;

        return $expiresAt > time() && $expiresAt > $createdAt + self::RESERVATION_SECONDS;
    }

    public function commit()
    {
        if (!$this->hasCapsule()) {
            $this->fallback()->commit();
            return;
        }

        if ($this->pendingEventId === '') {
            return;
        }

        \WHMCS\Database\Capsule::table(Migrations::EVENTS_TABLE)
            ->where('event_id', $this->pendingEventId)
            ->update(array('expires_at' => time() + $this->pendingTtlSeconds));

        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }

    public function release()
    {
        if (!$this->hasCapsule()) {
            $this->fallback()->release();
            return;
        }

        if ($this->pendingEventId === '') {
            return;
        }

        \WHMCS\Database\Capsule::table(Migrations::EVENTS_TABLE)
            ->where('event_id', $this->pendingEventId)
            ->delete();

        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }

    private function hasCapsule()
    {
        return class_exists('\\WHMCS\\Database\\Capsule');
    }

    private function fallback()
    {
        if ($this->fallback === null) {
            $this->fallback = new InMemoryEventStore();
        }

        return $this->fallback;
    }
}
