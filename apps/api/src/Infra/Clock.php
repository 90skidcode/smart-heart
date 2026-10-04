<?php

declare(strict_types=1);

namespace SmartHeart\Infra;

use DateTimeImmutable;
use DateTimeZone;

/** The current time in UTC. Injected so tests can freeze it. */
class Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** MySQL DATETIME(3) text for a UTC instant. */
    public static function sql(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** ISO-8601 UTC for API responses. */
    public static function iso(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** A DATETIME(3) value read from MySQL (always UTC) as ISO-8601, or null. */
    public static function isoFromSql(?string $sql): ?string
    {
        return $sql === null ? null : self::iso(new DateTimeImmutable($sql, new DateTimeZone('UTC')));
    }
}
