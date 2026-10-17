<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueEnv;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths;

/**
 * Builds isolated sqlite-queue broker env for controller/process tests.
 */
final class SessionSqliteQueueTestEnv
{
    /**
     * @return array<string, string>
     */
    public static function forSession(string $sessionsBasePath, string $sessionId): array
    {
        $paths = SessionSqliteQueuePaths::forSession($sessionsBasePath, $sessionId);

        return SessionSqliteQueueEnv::processEnv($paths);
    }
}
