<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

/**
 * Resolves the durable queue database and short Unix-socket endpoint for one
 * controller-root session's sqlite-queue broker.
 *
 * The database stays under configured session storage so ordinary shutdown and
 * resume keep queued work. The endpoint lives in a private 0700 per-launch
 * directory under /tmp because the package rejects sockets longer than 100 bytes
 * and requires private parent directories. A new launch never reuses a stale
 * endpoint path, so abrupt leftover sockets cannot permanently block resume.
 */
final readonly class SessionSqliteQueuePaths
{
    public const string DATABASE_BASENAME = 'messenger-queue.sqlite';

    /** Absolute Unix socket paths must stay under this package limit. */
    public const int MAX_ENDPOINT_BYTES = 100;

    private const string ENDPOINT_BASENAME = 'q.sock';

    public function __construct(
        public string $sessionId,
        public string $databasePath,
        public string $endpointPath,
    ) {
        self::assertSafeSessionId($this->sessionId);
        if ('' === $this->databasePath || !str_starts_with($this->databasePath, '/')) {
            throw new \InvalidArgumentException('SessionSqliteQueuePaths databasePath must be an absolute filesystem path.');
        }
        if ('' === $this->endpointPath || !str_starts_with($this->endpointPath, '/')) {
            throw new \InvalidArgumentException('SessionSqliteQueuePaths endpointPath must be an absolute filesystem path.');
        }
        if (\strlen($this->endpointPath) > self::MAX_ENDPOINT_BYTES) {
            throw new \InvalidArgumentException(\sprintf('SessionSqliteQueuePaths endpointPath exceeds %d bytes: %s', self::MAX_ENDPOINT_BYTES, $this->endpointPath));
        }
    }

    /**
     * @param string $sessionsBasePath Absolute configured sessions directory
     */
    public static function forSession(string $sessionsBasePath, string $sessionId): self
    {
        self::assertSafeSessionId($sessionId);
        if ('' === $sessionsBasePath || !str_starts_with($sessionsBasePath, '/')) {
            throw new \InvalidArgumentException('Sessions base path must be an absolute filesystem path.');
        }

        // Per-launch random private directory. Durable DB stays under the session path.
        $endpointDir = '/tmp/hq-'.bin2hex(random_bytes(8));
        $endpoint = $endpointDir.'/'.self::ENDPOINT_BASENAME;

        return new self(
            sessionId: $sessionId,
            databasePath: rtrim($sessionsBasePath, '/').'/'.$sessionId.'/'.self::DATABASE_BASENAME,
            endpointPath: $endpoint,
        );
    }

    /**
     * Build the six durable Messenger DSNs that share this broker endpoint.
     *
     * Queue names are not session-suffixed: the database file is already
     * per-session, so suffixes only burn identity length for no isolation gain.
     *
     * @return array{
     *     HATFIELD_RUN_CONTROL_TRANSPORT_DSN: string,
     *     HATFIELD_LLM_TRANSPORT_DSN: string,
     *     HATFIELD_TOOL_TRANSPORT_DSN: string,
     *     HATFIELD_AGENT_TRANSPORT_DSN: string,
     *     HATFIELD_MCP_TRANSPORT_DSN: string,
     *     HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN: string
     * }
     */
    public function transportDsns(): array
    {
        $endpoint = rawurlencode($this->endpointPath);

        return [
            'HATFIELD_RUN_CONTROL_TRANSPORT_DSN' => $this->dsn('run_control', $endpoint),
            'HATFIELD_LLM_TRANSPORT_DSN' => $this->dsn('llm', $endpoint),
            'HATFIELD_TOOL_TRANSPORT_DSN' => $this->dsn('tool', $endpoint),
            'HATFIELD_AGENT_TRANSPORT_DSN' => $this->dsn('agent', $endpoint),
            'HATFIELD_MCP_TRANSPORT_DSN' => $this->dsn('mcp', $endpoint),
            'HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN' => $this->dsn('extension_agent', $endpoint),
        ];
    }

    public function endpointDirectory(): string
    {
        return \dirname($this->endpointPath);
    }

    private function dsn(string $queueName, string $encodedEndpoint): string
    {
        return 'sqlite-queue://'.$queueName.'?endpoint='.$encodedEndpoint;
    }

    private static function assertSafeSessionId(string $sessionId): void
    {
        if ('' === $sessionId || str_contains($sessionId, "\0")) {
            throw new \InvalidArgumentException('Session ID must be a non-empty filesystem-safe token.');
        }

        // Fail closed on traversal and unknown identity shapes. Numeric Hatfield
        // IDs and test tokens such as e2e-* / session-* remain accepted.
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $sessionId)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported session identity "%s".', $sessionId));
        }
    }
}
