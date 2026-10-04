<?php

declare(strict_types=1);

namespace SmartHeart\Infra;

use DateTimeImmutable;
use DateTimeZone;

/** A clock that only moves when told to. For tests. */
final class FrozenClock extends Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $utc = '2026-10-03 10:00:00')
    {
        $this->now = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
