<?php

/**
 * Test bootstrap — loads Composer autoloader and provides minimal WordPress
 * function stubs so SDK classes can be exercised without a full WP install.
 *
 * The stubs are intentionally thin: enough to satisfy the paths these classes
 * take during tests, not a full mock. Tests that need richer behavior should
 * swap the stub state at runtime (see WpStub).
 */

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/WpStub.php';

VeronaLabs\WpPremiumSdk\Tests\WpStub::bootstrap();
