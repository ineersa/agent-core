<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Tool settings resolved from Hatfield config.
 *
 * Immutable value object. Contains tool output capping configuration.
 * More tool subsections will be added as the tool taxonomy expands.
 */
final readonly class ToolsConfig
{
    public function __construct(
        #[SerializedName('output_cap')]
        public OutputCapConfig $outputCap = new OutputCapConfig(),
    ) {
    }
}
