<?php

declare(strict_types=1);

namespace App\Store;

/** Thrown in production when a token would have to be saved without encryption. */
final class TokenKeyUnavailable extends \RuntimeException
{
}
