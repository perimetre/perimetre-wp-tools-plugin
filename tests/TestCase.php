<?php

declare(strict_types=1);

namespace Perimetre\WpTools\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Base for every unit test: WordPress functions and hooks are mocked with
 * Brain Monkey, so tests run without a WordPress install. A test that needs a
 * WordPress function stubs it (`Monkey\Functions\when('get_option')->...`);
 * one that calls an unstubbed WordPress function fails, which is the point.
 */
abstract class TestCase extends PhpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
