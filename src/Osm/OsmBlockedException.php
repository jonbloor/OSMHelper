<?php
declare(strict_types=1);
namespace App\Osm;

/**
 * OSM has blocked this application (X-Blocked). OSMHelper stops every OSM request, for every
 * user, until an administrator clears the block by hand (docs/DEPLOY-RUNBOOK.md, "OSM blocked").
 * Carrying on after X-Blocked can make OSM's block permanent, so this is never retried or timed out.
 * Extends OsmBusyException (code 429) so every existing "stop now" path also stops for this.
 */
final class OsmBlockedException extends OsmBusyException
{
    public const MESSAGE = 'OSM has blocked OSMHelper (X-Blocked), so OSMHelper has stopped all requests to OSM for everyone until the site administrator clears the block. Please don’t keep retrying.';

    public function __construct()
    {
        parent::__construct(0, OsmRateGuard::REASON_BLOCKED, self::MESSAGE);
    }
}
