<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Ide;

/**
 * Package-private one-shot JetBrains MCP client for task-workflow lifecycle.
 *
 * Uses host-installed mcp/sdk (project vendor). Connect → call one tool → always close.
 * Wire tool names are raw ide_*; never use the agent-visible jetbrains-index_ prefix.
 */
final class JetBrainsMcpClient
{
    private const SERVER_NAME = 'jetbrains-index';
    private const OPEN_TOOL = 'ide_open_project';
    private const CLOSE_TOOL = 'ide_close_project';
    private const OPEN_TIMEOUT_SECONDS = 600;

    public static function openWorktreeProject(string $codeRoot, string $worktree): string
    {
        try {
            self::callOnce($codeRoot, self::OPEN_TOOL, [
                'path' => $worktree,
                'timeoutSeconds' => self::OPEN_TIMEOUT_SECONDS,
                'project_path' => $codeRoot,
            ]);

            return 'Opened JetBrains project for worktree '.$worktree.'.';
        } catch (\Throwable $e) {
            return 'JetBrains project open degraded for '.$worktree.': '.self::sanitizeError($e).'. Filesystem tools remain available.';
        }
    }

    public static function closeWorktreeProject(string $codeRoot, string $worktree): string
    {
        try {
            self::callOnce($codeRoot, self::CLOSE_TOOL, [
                'project_path' => $worktree,
            ]);

            return 'Closed JetBrains project for worktree '.$worktree.'.';
        } catch (\Throwable $e) {
            return 'JetBrains project close degraded for '.$worktree.': '.self::sanitizeError($e).'.';
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function callOnce(string $codeRoot, string $toolName, array $arguments): void
    {
        if (!class_exists(\Mcp\Client::class) || !class_exists(\Mcp\Client\Transport\HttpTransport::class)) {
            throw new \RuntimeException('mcp/sdk client not available in host autoload');
        }

        // Match Pi: open allows OPEN_TIMEOUT_SECONDS + 30 overhead; close is short.
        $requestTimeoutSeconds = self::OPEN_TOOL === $toolName
            ? self::OPEN_TIMEOUT_SECONDS + 30
            : 60;

        $url = self::resolveServerUrl($codeRoot);
        $client = \Mcp\Client::builder()
            ->setClientInfo('hatfield-task-workflow', '1.0.0')
            ->setInitTimeout(10)
            ->setRequestTimeout($requestTimeoutSeconds)
            ->build();
        $transport = new \Mcp\Client\Transport\HttpTransport($url);

        try {
            $client->connect($transport);
            $result = $client->callTool($toolName, $arguments);
            if ($result->isError) {
                throw new \RuntimeException($toolName.' returned isError');
            }
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable $disconnectError) {
                // Intentional local degradation: disconnect errors must not mask call outcome.
                unset($disconnectError);
            }
        }
    }

    private static function resolveServerUrl(string $codeRoot): string
    {
        $configPath = rtrim($codeRoot, '/').'/.hatfield/mcp.json';
        if (!is_file($configPath)) {
            throw new \RuntimeException('Missing '.$configPath);
        }
        $raw = file_get_contents($configPath);
        if (false === $raw) {
            throw new \RuntimeException('Failed to read '.$configPath);
        }
        $parsed = json_decode($raw, true);
        if (!\is_array($parsed)) {
            throw new \RuntimeException('Invalid JSON in .hatfield/mcp.json');
        }
        $servers = $parsed['mcpServers'] ?? null;
        if (!\is_array($servers) || !isset($servers[self::SERVER_NAME]) || !\is_array($servers[self::SERVER_NAME])) {
            throw new \RuntimeException('MCP server "'.self::SERVER_NAME.'" missing in .hatfield/mcp.json');
        }
        $url = $servers[self::SERVER_NAME]['url'] ?? null;
        if (!\is_string($url) || '' === trim($url)) {
            throw new \RuntimeException('MCP server "'.self::SERVER_NAME.'" has no url in .hatfield/mcp.json');
        }

        return trim($url);
    }

    private static function sanitizeError(\Throwable $e): string
    {
        $raw = $e->getMessage();
        $scrubbed = preg_replace('#https?://\S+#i', '<url>', $raw) ?? $raw;
        $scrubbed = preg_replace('/(authorization|token|api[_-]?key|bearer)\s*[:=]\s*\S+/i', '$1=<redacted>', $scrubbed) ?? $scrubbed;
        $scrubbed = preg_replace('/\s+/', ' ', trim($scrubbed)) ?? trim($scrubbed);
        if (\strlen($scrubbed) > 240) {
            return substr($scrubbed, 0, 240).'…';
        }

        return $scrubbed;
    }
}
