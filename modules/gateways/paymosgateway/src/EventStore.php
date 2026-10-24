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

    /**
     * Owner token of the reservation remember() took (A10-02). Each successful
     * remember() mints a fresh random token; commit()/release() match it in
     * their WHERE clause, so a delivery whose reservation expired (and whose
     * event id was taken over by a newer delivery) can no longer delete or
     * commit the successor's row.
     *
     * @var string
     */
    private $pendingOwnerToken = '';

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

        $ownerToken = bin2hex(random_bytes(16));

        try {
            \WHMCS\Database\Capsule::table(Migrations::EVENTS_TABLE)->insert(array(
                'event_id' => $eventId,
                'owner_token' => $ownerToken,
                'expires_at' => $now + self::RESERVATION_SECONDS,
                'created_at' => $now,
            ));
        } catch (\Exception $e) {
            return false;
        }

        $this->pendingEventId = $eventId;
        $this->pendingOwnerToken = $ownerToken;
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

    /**
     * Extend the reservation to the full dedup window — but only if this store
     * instance still owns it. Zero affected rows mean a newer delivery took the
     * event id over after this reservation expired (A10-02): that successor's
     * row must stay untouched, and the commit is reported as not owned (false),
     * not as an error.
     *
     * @return bool True when this owner's row was extended; false when nothing
     *              was pending or the reservation was lost to a successor.
     */
    public function commit()
    {
        if (!$this->hasCapsule()) {
            return $this->fallback()->commit();
        }

        if ($this->pendingEventId === '') {
            return false;
        }

        $affected = \WHMCS\Database\Capsule::table(Migrations::EVENTS_TABLE)
            ->where('event_id', $this->pendingEventId)
            ->where('owner_token', $this->pendingOwnerToken)
            ->update(array('expires_at' => time() + $this->pendingTtlSeconds));

        $this->pendingEventId = '';
        $this->pendingOwnerToken = '';
        $this->pendingTtlSeconds = 0;

        return (int) $affected > 0;
    }

    /**
     * Drop the in-flight lock so the server can retry a failed processing run.
     * Only this owner's row is deleted: when the reservation expired and a
     * newer delivery owns the event id now, release() completes silently and
     * leaves the successor's row alone (A10-02).
     */
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
            ->where('owner_token', $this->pendingOwnerToken)
            ->delete();

        $this->pendingEventId = '';
        $this->pendingOwnerToken = '';
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
