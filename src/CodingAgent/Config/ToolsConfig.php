<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Tool settings resolved from Hatfield config.
 *
 * Immutable value object containing typed tool configuration sections.
 */
final readonly class ToolsConfig
{
    public function __construct(
        #[SerializedName('execution')]
        public ToolExecutionConfig $execution = new ToolExecutionConfig(),

        #[SerializedName('output_cap')]
        public OutputCapConfig $outputCap = new OutputCapConfig(),

        #[SerializedName('background_process')]
        public BackgroundProcessConfig $backgroundProcess = new BackgroundProcessConfig(),

        #[SerializedName('image')]
        public ImageToolConfig $image = new ImageToolConfig(),

        #[SerializedName('bash')]
        public BashToolConfig $bash = new BashToolConfig(),
    ) {
    }
}
