<?php
declare(strict_types=1);
namespace App\Osm;

/**
 * OSMHelper refused to call OSM (no request was sent) because this user's OSM allowance
 * is in backoff after a 429, or is running low. Code 429 so existing "stop on 429" handling applies.
 */
final class OsmBusyException extends \RuntimeException
{
    public function __construct(private int $waitSeconds, private string $reason)
    {
        parent::__construct(self::friendly($waitSeconds), 429);
    }

    public function waitSeconds(): int
    {
        return $this->waitSeconds;
    }

    public function waitMinutes(): int
    {
        return max(1, (int) ceil($this->waitSeconds / 60));
    }

    /** Reason code only (osm_backoff_429 | osm_backoff_exhausted | osm_rate_low). */
    public function reason(): string
    {
        return $this->reason;
    }

    public static function friendly(int $waitSeconds): string
    {
        $m = max(1, (int) ceil($waitSeconds / 60));
        return 'OSM is busy, try again in ' . $m . ' minute' . ($m === 1 ? '' : 's') . '. OSMHelper has paused its requests to protect your OSM allowance.';
    }
}
