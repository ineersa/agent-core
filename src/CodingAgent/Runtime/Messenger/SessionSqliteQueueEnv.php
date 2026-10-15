<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

/**
 * Process-env keys for one controller-root sqlite-queue broker.
 *
 * The parent process writes these before spawning the controller so Symfony can
 * resolve Messenger DSNs at consumer boot. The controller starts the broker from
 * the same database and endpoint values.
 */
final class SessionSqliteQueueEnv
{
    public const string DATABASE_PATH = 'HATFIELD_SQLITE_QUEUE_DATABASE';
    public const string ENDPOINT_PATH = 'HATFIELD_SQLITE_QUEUE_ENDPOINT';

    /**
     * @return array<string, string>
     */
    public static function processEnv(SessionSqliteQueuePaths $paths): array
    {
        return [
            self::DATABASE_PATH => $paths->databasePath,
            self::ENDPOINT_PATH => $paths->endpointPath,
            ...$paths->transportDsns(),
        ];
    }

    public static function pathsFromEnvironment(string $sessionId): SessionSqliteQueuePaths
    {
        // Constructor validates session identity; do not accept unknown/default IDs.
        $database = self::required(self::DATABASE_PATH);
        $endpoint = self::required(self::ENDPOINT_PATH);

        return new SessionSqliteQueuePaths($sessionId, $database, $endpoint);
    }

    private static function required(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        if (!\is_string($value) || '' === $value) {
            throw new \RuntimeException(\sprintf('Missing required sqlite-queue environment variable %s.', $name));
        }

        return $value;
    }
}
