<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Mutable per-invocation tool allowlist/denylist for process-transport propagation.
 *
 * Populated by AgentCommand from CLI options, forwarded to controller argv + env,
 * and reapplied after extension loading in controller/worker processes.
 *
 * @internal
 */
final class ToolFilterRuntimeConfig
{
    /**
     * Canonical comma-separated allowlist. Empty means the allowlist option was omitted
     * (all tools visible, subject to exclusions).
     */
    public string $tools = '';

    /**
     * Canonical comma-separated denylist. Empty means the denylist option was omitted.
     */
    public string $toolsExcluded = '';

    /**
     * Store CLI filter strings and apply them to the local registry.
     *
     * Unknown names throw via {@see ToolRegistryInterface::setAllowedToolNames()} /
     * {@see ToolRegistryInterface::setExcludedToolNames()}.
     */
    public function applyFromCli(string $tools, string $toolsExcluded, ?ToolRegistryInterface $toolRegistry): void
    {
        $this->tools = $tools;
        $this->toolsExcluded = $toolsExcluded;
        $this->applyToRegistry($toolRegistry);
    }

    /**
     * When running outside AgentCommand (messenger:consume workers), hydrate from
     * dedicated internal env vars set by the process-transport spawn path.
     *
     * Does not overwrite non-empty state already set from CLI in this process.
     */
    public function hydrateFromEnvironment(): void
    {
        if ('' === $this->tools) {
            $fromEnv = $this->envValue('HATFIELD_TOOLS');
            if (null !== $fromEnv && '' !== $fromEnv) {
                $this->tools = $fromEnv;
            }
        }

        if ('' === $this->toolsExcluded) {
            $fromEnv = $this->envValue('HATFIELD_TOOLS_EXCLUDED');
            if (null !== $fromEnv && '' !== $fromEnv) {
                $this->toolsExcluded = $fromEnv;
            }
        }
    }

    public function applyToRegistry(?ToolRegistryInterface $toolRegistry): void
    {
        if (null === $toolRegistry) {
            if ('' !== $this->tools || '' !== $this->toolsExcluded) {
                throw new \RuntimeException('--tools and --tools-excluded require ToolRegistry to be wired.');
            }

            return;
        }

        if ('' !== $this->tools) {
            $toolRegistry->setAllowedToolNames(self::parseToolNameList($this->tools));
        }

        if ('' !== $this->toolsExcluded) {
            $toolRegistry->setExcludedToolNames(self::parseToolNameList($this->toolsExcluded));
        }
    }

    /**
     * Dedicated controller argv fragments. Empty filters omit their options.
     *
     * @return list<string>
     */
    public function controllerArgs(): array
    {
        $args = [];
        if ('' !== $this->tools) {
            $args[] = '--tools='.$this->tools;
        }
        if ('' !== $this->toolsExcluded) {
            $args[] = '--tools-excluded='.$this->toolsExcluded;
        }

        return $args;
    }

    /**
     * Dedicated internal env values for messenger workers (and controller inheritance).
     *
     * @return array<string, string>
     */
    public function processEnv(): array
    {
        $env = [];
        if ('' !== $this->tools) {
            $env['HATFIELD_TOOLS'] = $this->tools;
        }
        if ('' !== $this->toolsExcluded) {
            $env['HATFIELD_TOOLS_EXCLUDED'] = $this->toolsExcluded;
        }

        return $env;
    }

    /**
     * Parse a comma-separated tool name list into a deduplicated array.
     *
     * Trims whitespace around each entry, drops empty tokens, and
     * preserves insertion order. Empty input yields an empty list.
     *
     * @return list<string>
     */
    public static function parseToolNameList(string $raw): array
    {
        return array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', $raw)),
            static fn (string $name): bool => '' !== $name,
        )));
    }

    private function envValue(string $key): ?string
    {
        if (\array_key_exists($key, $_ENV) && \is_string($_ENV[$key])) {
            return $_ENV[$key];
        }
        if (\array_key_exists($key, $_SERVER) && \is_string($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        $value = getenv($key);

        return false === $value ? null : $value;
    }
}
