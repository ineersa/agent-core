<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Typed DTO for the tools.execution.* settings section.
 *
 * Hydrated by Symfony Serializer/Denormalizer from the merged Hatfield
 * config array (defaults.yaml → home settings → project settings).
 *
 * Execution mode per tool is set at registration time by the tool
 * author/provider in ToolDefinitionDTO, not from settings overrides.
 * File-mutation tools (write, edit) are explicitly registered as
 * Sequential in their HatfieldToolProviderInterface::definition().
 */
final readonly class ToolExecutionConfig
{
    public const string DEFAULT_MODE = 'sequential';
    // No global ToolExecutor post-hoc timeout by default; tools opt in via ToolDefinitionDTO.
    public const int DEFAULT_MAX_PARALLELISM = 4;

    /**
     * @param string   $defaultMode    Default execution mode ('sequential' or 'parallel')
     * @param int|null $timeoutSeconds Optional global ToolExecutor timeout; null disables post-hoc timeout
     * @param int      $maxParallelism Maximum concurrent tool calls
     */
    public function __construct(
        #[SerializedName('default_mode')]
        public string $defaultMode = self::DEFAULT_MODE,

        #[SerializedName('timeout_seconds')]
        public ?int $timeoutSeconds = null,

        #[SerializedName('max_parallelism')]
        public int $maxParallelism = self::DEFAULT_MAX_PARALLELISM,
    ) {
    }
}
