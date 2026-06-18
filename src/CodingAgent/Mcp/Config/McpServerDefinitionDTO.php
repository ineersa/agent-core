<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Config;

/**
 * Typed MCP server definition.
 *
 * Represents a single server entry from .hatfield/mcp.json.
 * Immutable value object constructed via {@see fromArray()}.
 */
final readonly class McpServerDefinitionDTO
{
    /**
     * @param string                    $name             Server name key from mcpServers map
     * @param bool                      $enabled          Whether this server is active
     * @param string|null               $command          STDIO command path / binary name
     * @param list<string>              $args             STDIO command arguments
     * @param array<string, string>     $env              STDIO environment variables (interpolated)
     * @param string|null               $cwd              STDIO working directory (resolved)
     * @param string|null               $url              HTTP server endpoint URL
     * @param array<string, string>     $headers          HTTP request headers (interpolated)
     * @param int                       $timeoutMs        Tool-call timeout in milliseconds
     * @param int                       $startupTimeoutMs STDIO startup timeout in milliseconds
     * @param list<string>              $excludeTools     Tool names to exclude from registration
     * @param McpTransportTypeEnum|null $transportType    Resolved transport type; null only when neither command nor url is set (e.g. inherited disable-only)
     */
    public function __construct(
        public string $name,
        public bool $enabled = true,
        public ?string $command = null,
        public array $args = [],
        public array $env = [],
        public ?string $cwd = null,
        public ?string $url = null,
        public array $headers = [],
        public int $timeoutMs = 30000,
        public int $startupTimeoutMs = 30000,
        public array $excludeTools = [],
        public ?McpTransportTypeEnum $transportType = null,
    ) {
    }

    /**
     * Build from a raw JSON-decoded array for a single server definition.
     *
     * @param string              $name Server name key
     * @param array<string,mixed> $data Raw config values
     *
     * @throws \RuntimeException when field types are invalid
     *
     * The DTO re-validates certain fields (e.g. enabled type) even though
     * {@see McpConfigValidator} normally runs first, because fromArray() is
     * a public standalone entry point that callers may use directly.
     */
    public static function fromArray(string $name, array $data): self
    {
        $enabled = true;
        if (\array_key_exists('enabled', $data)) {
            if (!\is_bool($data['enabled'])) {
                throw new \RuntimeException(\sprintf('MCP server "%s": "enabled" must be a boolean, got %s.', $name, \gettype($data['enabled'])));
            }
            $enabled = $data['enabled'];
        }

        // Resolve transport type from field presence
        $hasCommand = isset($data['command']);
        $hasUrl = isset($data['url']);

        $command = null;
        $args = [];
        $env = [];
        $cwd = null;
        $url = null;
        $headers = [];
        $transportType = null;

        if ($hasCommand) {
            $command = self::requireNonEmptyString($data['command'], $name, 'command');

            if (isset($data['args'])) {
                $args = self::requireStringList($data['args'], $name, 'args');
            }

            if (isset($data['env'])) {
                $env = self::requireStringMap($data['env'], $name, 'env');
            }

            if (isset($data['cwd'])) {
                $cwd = self::requireNonEmptyString($data['cwd'], $name, 'cwd');
            }

            $transportType = McpTransportTypeEnum::STDIO;
        }

        if ($hasUrl) {
            $url = self::requireNonEmptyString($data['url'], $name, 'url');

            if (isset($data['headers'])) {
                $headers = self::requireStringMap($data['headers'], $name, 'headers');
            }

            $transportType = McpTransportTypeEnum::HTTP;
        }

        $timeoutMs = 30000;
        if (\array_key_exists('timeoutMs', $data)) {
            $timeoutMs = self::requirePositiveInt($data['timeoutMs'], $name, 'timeoutMs');
        }

        $startupTimeoutMs = 30000;
        if (\array_key_exists('startupTimeoutMs', $data)) {
            $startupTimeoutMs = self::requirePositiveInt($data['startupTimeoutMs'], $name, 'startupTimeoutMs');
        }

        $excludeTools = [];
        if (isset($data['excludeTools'])) {
            $excludeTools = self::requireStringList($data['excludeTools'], $name, 'excludeTools');
        }

        return new self(
            name: $name,
            enabled: $enabled,
            command: $command,
            args: $args,
            env: $env,
            cwd: $cwd,
            url: $url,
            headers: $headers,
            timeoutMs: $timeoutMs,
            startupTimeoutMs: $startupTimeoutMs,
            excludeTools: $excludeTools,
            transportType: $transportType,
        );
    }

    /**
     * @throws \RuntimeException
     */
    private static function requireNonEmptyString(mixed $value, string $name, string $field): string
    {
        if (!\is_string($value) || '' === $value) {
            throw new \RuntimeException(\sprintf('MCP server "%s": "%s" must be a non-empty string.', $name, $field));
        }

        return $value;
    }

    /**
     * @return list<string>
     *
     * @throws \RuntimeException
     */
    private static function requireStringList(mixed $value, string $name, string $field): array
    {
        if (!\is_array($value)) {
            throw new \RuntimeException(\sprintf('MCP server "%s": "%s" must be an array.', $name, $field));
        }

        if ([] === $value) {
            return [];
        }

        if (!array_is_list($value)) {
            throw new \RuntimeException(\sprintf('MCP server "%s": "%s" must be a list (sequential array).', $name, $field));
        }

        foreach ($value as $i => $item) {
            if (!\is_string($item)) {
                throw new \RuntimeException(\sprintf('MCP server "%s": "%s[%d]" must be a string, got %s.', $name, $field, $i, \gettype($item)));
            }
        }

        return $value;
    }

    /**
     * @return array<string, string>
     *
     * @throws \RuntimeException
     */
    private static function requireStringMap(mixed $value, string $name, string $field): array
    {
        if (!\is_array($value)) {
            throw new \RuntimeException(\sprintf('MCP server "%s": "%s" must be an object with string values.', $name, $field));
        }

        foreach ($value as $k => $v) {
            if (!\is_string($v)) {
                throw new \RuntimeException(\sprintf('MCP server "%s": "%s.%s" must be a string, got %s.', $name, $field, (string) $k, \gettype($v)));
            }
        }

        /* @var array<string, string> */
        return $value;
    }

    /**
     * @throws \RuntimeException
     */
    private static function requirePositiveInt(mixed $value, string $name, string $field): int
    {
        if (!\is_int($value) || $value < 1) {
            throw new \RuntimeException(\sprintf('MCP server "%s": "%s" must be a positive integer, got %s.', $name, $field, \is_int($value) ? (string) $value : \gettype($value)));
        }

        return $value;
    }
}
