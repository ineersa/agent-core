<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Tool settings resolved from Hatfield config.
 *
 * Immutable value object containing typed tool configuration sections.
 */
final readonly class ToolsConfig
{
    public function __construct(
        public ToolExecutionConfig $execution = new ToolExecutionConfig(),

        public OutputCapConfig $outputCap = new OutputCapConfig(),

        public BackgroundProcessConfig $backgroundProcess = new BackgroundProcessConfig(),

        public ImageToolConfig $image = new ImageToolConfig(),

        public BashToolConfig $bash = new BashToolConfig(),
    ) {
    }
}
