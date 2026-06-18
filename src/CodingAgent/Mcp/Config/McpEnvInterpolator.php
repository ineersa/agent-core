<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Config;

/**
 * Interpolates environment variable references in MCP server config values.
 *
 * Replaces `${VAR_NAME}` placeholders in env and headers map values with
 * actual runtime environment variable values.
 *
 * Missing or empty interpolated variables are treated as configuration errors
 * because they are typically used for secrets/tokens where an empty value
 * would produce silently broken behavior (e.g. "Bearer " with no token).
 *
 * Literal empty-string values that do not contain `${...}` references are
 * allowed unchanged.
 *
 * Error messages intentionally include the variable name and server/field
 * context but never the resolved secret value or headers.
 */
final class McpEnvInterpolator
{
    /**
     * Interpolate `${VAR}` references in all values of a string map.
     *
     * @param array<string, string> $map    The map to interpolate (e.g. env or headers)
     * @param string                $server Server name for error context
     * @param string                $field  Field name for error context (e.g. "env" or "headers")
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException when a referenced env var is missing or resolves to an empty string
     */
    public function interpolateMap(array $map, string $server, string $field): array
    {
        $result = [];

        foreach ($map as $key => $value) {
            $result[$key] = $this->interpolateValue($value, $server, \sprintf('%s.%s', $field, $key));
        }

        return $result;
    }

    /**
     * Interpolate a single string value.
     *
     * @param string $value   The raw value possibly containing ${VAR} references
     * @param string $server  Server name for error context
     * @param string $context Field/path for error context
     *
     * @return string The interpolated value
     *
     * @throws \RuntimeException when a referenced env var is missing or empty
     */
    public function interpolateValue(string $value, string $server, string $context): string
    {
        // Fast path: no interpolation needed
        if (!str_contains($value, '${')) {
            return $value;
        }

        return preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $matches) use ($server, $context): string {
                $varName = $matches[1];
                $resolved = getenv($varName);

                if (false === $resolved) {
                    throw new \RuntimeException(\sprintf('MCP server "%s": environment variable "%s" referenced in "%s" is not set.', $server, $varName, $context));
                }

                if ('' === $resolved) {
                    throw new \RuntimeException(\sprintf('MCP server "%s": environment variable "%s" referenced in "%s" is empty. Set the variable or remove the interpolation reference.', $server, $varName, $context));
                }

                return $resolved;
            },
            $value,
        );
    }
}
