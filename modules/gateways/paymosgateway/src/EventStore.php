<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\Webhook\EventStoreInterface;

final class EventStore implements EventStoreInterface
{
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
                'expires_at' => $now + 300,
                'created_at' => $now,
            ));
        } catch (\Exception $e) {
            return false;
        }

        $this->pendingEventId = $eventId;
        $this->pendingTtlSeconds = (int) $ttlSeconds;

        return true;
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
