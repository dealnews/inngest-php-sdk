<?php

/**
 * Router script for the functional test app
 *
 * Started by tests/functional/docker-compose.yml as
 * `php -S 0.0.0.0:8000 tests/functional/app/server.php`.
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

\DealNews\Inngest\Tests\functional\app\TestApp::fromEnv()->serve();
