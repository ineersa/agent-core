<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Bounded output limits for parent-scoped subagent artifact retrieval.
 *
 * Hydrated from Hatfield `agents.retrieve` settings with stable defaults.
 */
final readonly class AgentArtifactRetrievalLimitsConfig
{
    public function __construct(
        #[SerializedName('default_limit')]
        public int $defaultLimit = 20,
        #[SerializedName('max_limit')]
        public int $maxLimit = 100,
        #[SerializedName('history_summary_chars')]
        public int $historySummaryChars = 240,
    ) {
    }

    public static function fromRaw(mixed $raw): self
    {
        if (!\is_array($raw)) {
            return new self();
        }

        $defaultLimit = 20;
        if (isset($raw['default_limit']) && \is_int($raw['default_limit'])) {
            $defaultLimit = $raw['default_limit'];
        }

        $maxLimit = 100;
        if (isset($raw['max_limit']) && \is_int($raw['max_limit'])) {
            $maxLimit = $raw['max_limit'];
        }

        $historySummaryChars = 240;
        if (isset($raw['history_summary_chars']) && \is_int($raw['history_summary_chars'])) {
            $historySummaryChars = $raw['history_summary_chars'];
        }

        return new self(
            defaultLimit: $defaultLimit,
            maxLimit: $maxLimit,
            historySummaryChars: $historySummaryChars,
        );
    }

    public static function fromAgentsConfig(AgentsConfig $agentsConfig): self
    {
        return $agentsConfig->retrieve;
    }

    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return self::fromAgentsConfig($appConfig->agents);
    }
}
