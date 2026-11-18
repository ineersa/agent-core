<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Claude;

use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;

/**
 * OM settings for Claude Code from the same `.hatfield/settings.yaml` layers
 * Hatfield reads, so one Librarian configuration serves both agents.
 */
final readonly class ClaudeOmSettingsProvider
{
    public function __construct(private ?string $home)
    {
    }

    /** Null when Librarian is not configured; invalid settings still throw. */
    public function settings(string $projectRoot): ?OmSettings
    {
        $raw = (new HatfieldSettingsReader($this->home))->extensionSettings($projectRoot)[OmSettings::SETTINGS_KEY] ?? [];
        if (!\is_array($raw) || !isset($raw['librarian'])) {
            return null;
        }

        return OmSettings::fromArray($raw);
    }
}
