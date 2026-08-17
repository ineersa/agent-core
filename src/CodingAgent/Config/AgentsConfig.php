<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Top-level Hatfield `agents:` settings.
 *
 * Controls agent definition discovery and allows explicit additional
 * agent definition file/directory paths.
 *
 * Path resolution (tilde, %kernel.project_dir%, relative paths) is handled
 * by the declarative PATH_CONFIG entry in AppConfigLoader, not here.
 */
final readonly class AgentsConfig
{
    private const SUBAGENT_TOOL_TIMEOUT_SECONDS_MIN = 60;

    private const SUBAGENT_TOOL_TIMEOUT_SECONDS_DEFAULT = 86400;

    /**
     * @param bool         $enabled               Whether agent discovery is enabled
     * @param list<string> $paths                 Additional agent definition file or directory paths
     * @param int          $maxAgents             Maximum parallel subagents per `subagent` tool call
     * @param list<string> $subagentExcludedTools Tool names removed from child agents by default/configuration
     */
    public function __construct(
        public bool $enabled = true,
        public array $paths = [],
        public int $maxAgents = 4,

        public int $subagentToolTimeoutSeconds = 86400,

        public array $subagentExcludedTools = ['settings', 'hatfield_docs'],

        public ChildExtensionsConfigDTO $extensions = new ChildExtensionsConfigDTO(),
    ) {
    }

    /**
     * Build from raw config data (e.g. a YAML-parsed array).
     *
     * Explicitly configured values are validated strictly: a malformed
     * section or entry fails configuration load instead of being ignored
     * or replaced by a default. Omission (the key absent from the merged
     * config) is handled by the caller and yields the defaults.
     */
    public static function fromRaw(mixed $raw): self
    {
        if (null === $raw) {
            throw new \InvalidArgumentException('Invalid value for agents: expected mapping, got null.');
        }

        if (!\is_array($raw)) {
            throw new \InvalidArgumentException(\sprintf('Invalid value for agents: expected mapping, got %s.', get_debug_type($raw)));
        }

        if ([] !== $raw && array_is_list($raw)) {
            throw new \InvalidArgumentException('Invalid value for agents: expected mapping, got list.');
        }

        $unknown = array_diff(array_keys($raw), [
            'enabled',
            'paths',
            'max_agents',
            'subagent_tool_timeout_seconds',
            'subagent_excluded_tools',
            'extensions',
        ]);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('Invalid key for agents: "%s" is not supported.', reset($unknown)));
        }

        $enabled = true;
        if (\array_key_exists('enabled', $raw)) {
            if (!\is_bool($raw['enabled'])) {
                throw new \InvalidArgumentException(\sprintf('Invalid value for agents.enabled: expected boolean, got %s.', get_debug_type($raw['enabled'])));
            }
            $enabled = $raw['enabled'];
        }

        $paths = [];
        if (\array_key_exists('paths', $raw)) {
            $pathsValue = $raw['paths'];
            if (!\is_array($pathsValue)) {
                throw new \InvalidArgumentException(\sprintf('Invalid value for agents.paths: expected list of strings, got %s.', get_debug_type($pathsValue)));
            }
            if (!array_is_list($pathsValue)) {
                throw new \InvalidArgumentException('Invalid value for agents.paths: expected list of strings, got associative array.');
            }
            foreach ($pathsValue as $index => $value) {
                if (!\is_string($value)) {
                    throw new \InvalidArgumentException(\sprintf('Invalid value for agents.paths[%d]: expected a non-empty string, got %s.', $index, get_debug_type($value)));
                }
                if ('' === trim($value)) {
                    throw new \InvalidArgumentException(\sprintf('Invalid value for agents.paths[%d]: expected a non-empty string, got blank string.', $index));
                }
                $paths[] = $value;
            }
        }

        $maxAgents = 4;
        if (\array_key_exists('max_agents', $raw)) {
            if (!\is_int($raw['max_agents'])) {
                throw new \InvalidArgumentException(\sprintf('Invalid value for agents.max_agents: expected a positive integer, got %s.', get_debug_type($raw['max_agents'])));
            }
            if ($raw['max_agents'] <= 0) {
                throw new \InvalidArgumentException(\sprintf('Invalid value for agents.max_agents: %d is not a positive integer.', $raw['max_agents']));
            }
            $maxAgents = $raw['max_agents'];
        }

        $subagentToolTimeoutSeconds = self::resolveSubagentToolTimeoutSeconds($raw);
        $subagentExcludedTools = self::resolveSubagentExcludedTools($raw);
        $extensions = \array_key_exists('extensions', $raw)
            ? ChildExtensionsConfigDTO::fromRaw($raw['extensions'], 'agents.extensions', acceptEnabled: false)
            : new ChildExtensionsConfigDTO();

        return new self(
            enabled: $enabled,
            paths: $paths,
            maxAgents: $maxAgents,
            subagentToolTimeoutSeconds: $subagentToolTimeoutSeconds,
            subagentExcludedTools: $subagentExcludedTools,
            extensions: $extensions,
        );
    }

    /**
     * Extract from the resolved AppConfig.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->agents;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function resolveSubagentToolTimeoutSeconds(array $raw): int
    {
        if (!\array_key_exists('subagent_tool_timeout_seconds', $raw)) {
            return self::SUBAGENT_TOOL_TIMEOUT_SECONDS_DEFAULT;
        }

        $value = $raw['subagent_tool_timeout_seconds'];
        if (!\is_int($value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid value for agents.subagent_tool_timeout_seconds: expected integer >= %d, got %s.', self::SUBAGENT_TOOL_TIMEOUT_SECONDS_MIN, get_debug_type($value)));
        }

        if ($value < self::SUBAGENT_TOOL_TIMEOUT_SECONDS_MIN) {
            throw new \InvalidArgumentException(\sprintf('Invalid value for agents.subagent_tool_timeout_seconds: %d is below the minimum of %d seconds.', $value, self::SUBAGENT_TOOL_TIMEOUT_SECONDS_MIN));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<string>
     */
    private static function resolveSubagentExcludedTools(array $raw): array
    {
        if (!\array_key_exists('subagent_excluded_tools', $raw)) {
            return ['settings', 'hatfield_docs'];
        }

        $value = $raw['subagent_excluded_tools'];
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid value for agents.subagent_excluded_tools: expected list of strings, got %s.', get_debug_type($value)));
        }

        $tools = [];
        foreach ($value as $item) {
            if (!\is_string($item) || '' === trim($item)) {
                throw new \InvalidArgumentException('Invalid value for agents.subagent_excluded_tools: every entry must be a non-empty string.');
            }
            $tools[] = $item;
        }

        return array_values(array_unique($tools));
    }
}
