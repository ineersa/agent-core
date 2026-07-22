<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

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

    private const SUBAGENT_TOOL_TIMEOUT_SECONDS_DEFAULT = 1800;

    /**
     * @param bool         $enabled               Whether agent discovery is enabled
     * @param list<string> $paths                 Additional agent definition file or directory paths
     * @param int          $maxAgents             Maximum parallel subagents per `subagent` tool call
     * @param list<string> $subagentExcludedTools Tool names removed from child agents by default/configuration
     */
    public function __construct(
        public bool $enabled = true,
        public array $paths = [],
        #[SerializedName('retrieve')]
        public AgentArtifactRetrievalLimitsConfig $retrieve = new AgentArtifactRetrievalLimitsConfig(),
        #[SerializedName('max_agents')]
        public int $maxAgents = 8,

        #[SerializedName('subagent_tool_timeout_seconds')]
        public int $subagentToolTimeoutSeconds = 1800,

        #[SerializedName('subagent_excluded_tools')]
        public array $subagentExcludedTools = ['settings', 'hatfield_docs'],
    ) {
    }

    /**
     * Build from raw config data (e.g. a YAML-parsed array).
     *
     * Non-array input and non-string / blank string entries are silently
     * ignored (the discovery service treats missing paths as diagnostics).
     */
    public static function fromRaw(mixed $raw): self
    {
        if (!\is_array($raw)) {
            return new self();
        }

        $enabled = true;
        if (\array_key_exists('enabled', $raw) && \is_bool($raw['enabled'])) {
            $enabled = $raw['enabled'];
        }

        $paths = [];
        $rawPaths = $raw['paths'] ?? [];
        if (\is_array($rawPaths)) {
            foreach ($rawPaths as $value) {
                if (\is_string($value) && '' !== trim($value)) {
                    $paths[] = $value;
                }
            }
        }

        $retrieve = AgentArtifactRetrievalLimitsConfig::fromRaw($raw['retrieve'] ?? []);

        $maxAgents = 8;
        if (\array_key_exists('max_agents', $raw) && \is_int($raw['max_agents']) && $raw['max_agents'] > 0) {
            $maxAgents = $raw['max_agents'];
        }

        $subagentToolTimeoutSeconds = self::resolveSubagentToolTimeoutSeconds($raw);
        $subagentExcludedTools = self::resolveSubagentExcludedTools($raw);

        return new self(
            enabled: $enabled,
            paths: $paths,
            retrieve: $retrieve,
            maxAgents: $maxAgents,
            subagentToolTimeoutSeconds: $subagentToolTimeoutSeconds,
            subagentExcludedTools: $subagentExcludedTools,
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
