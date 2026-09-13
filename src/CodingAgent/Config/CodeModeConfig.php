<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * code_mode tool settings resolved from Hatfield config.
 *
 * Hydrated from the tools.code_mode section of merged Hatfield settings.
 * Default remains disabled so arbitrary PHP execution stays opt-in.
 */
final readonly class CodeModeConfig
{
    public function __construct(
        public bool $enabled = false,
    ) {
    }

    /**
     * DI factory — extract code_mode settings from AppConfig.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->tools->codeMode;
    }
}
