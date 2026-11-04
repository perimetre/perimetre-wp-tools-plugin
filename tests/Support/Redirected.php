<?php

declare(strict_types=1);

namespace Perimetre\WpTools\Tests\Support;

use RuntimeException;

/**
 * Thrown by the test double of `wp_safe_redirect`. Every login path ends in a
 * redirect followed by `exit`, so throwing here stops the code at the redirect
 * and lets the test read where it was sent.
 */
final class Redirected extends RuntimeException
{
    public function __construct(public readonly string $location)
    {
        parent::__construct('Redirected to ' . $location);
    }
}
