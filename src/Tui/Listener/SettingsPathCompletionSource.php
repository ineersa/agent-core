<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\Tui\Completion\SettingsPathCompletionSourceInterface;

/** AppConfig stays in TuiListener (same Deptrac pattern as SessionCompletionSource). */
final readonly class SettingsPathCompletionSource implements SettingsPathCompletionSourceInterface
{
    public function __construct(
        private AppConfigLoader $loader,
        private AppResourceLocator $resources,
        private AppConfig $activeConfig,
    ) {
    }

    public function loadEffectiveSettings(): array
    {
        return $this->loader->load($this->resources->getDefaultsPath(), $this->activeConfig->cwd)->effective;
    }
}
