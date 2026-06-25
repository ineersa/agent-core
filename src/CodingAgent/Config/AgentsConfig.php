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
    /**
     * @param bool         $enabled   Whether agent discovery is enabled
     * @param list<string> $paths     Additional agent definition file or directory paths
     * @param int          $maxAgents Maximum parallel subagents per `subagent` tool call
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

        $subagentToolTimeoutSeconds = 1800;
        if (\array_key_exists('subagent_tool_timeout_seconds', $raw) && \is_int($raw['subagent_tool_timeout_seconds']) && $raw['subagent_tool_timeout_seconds'] > 0) {
            $subagentToolTimeoutSeconds = $raw['subagent_tool_timeout_seconds'];
        }

        return new self(enabled: $enabled, paths: $paths, retrieve: $retrieve, maxAgents: $maxAgents, subagentToolTimeoutSeconds: $subagentToolTimeoutSeconds);
    }

    /**
     * Extract from the resolved AppConfig.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->agents;
    }
}
