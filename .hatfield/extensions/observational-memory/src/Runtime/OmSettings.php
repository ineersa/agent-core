<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;

/**
 * Extension-local settings for observational memory.
 *
 * Read from extensions.settings.observational_memory via ExtensionApi.
 */
final readonly class OmSettings
{
    public const SETTINGS_KEY = 'observational_memory';

    public const DEFAULT_RELATIVE_DB_PATH = '.hatfield/extensions-data/observational-memory/om.sqlite';

    public function __construct(
        public bool $enabled,
        public string $databasePath,
    ) {
    }

    public static function fromApi(ExtensionApiInterface $api): self
    {
        $raw = $api->getSettings(self::SETTINGS_KEY);

        $enabled = true;
        if (\array_key_exists('enabled', $raw)) {
            $enabled = filter_var($raw['enabled'], \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
        }

        $databasePath = self::DEFAULT_RELATIVE_DB_PATH;
        if (isset($raw['database_path']) && \is_string($raw['database_path']) && '' !== $raw['database_path']) {
            $databasePath = $raw['database_path'];
        }

        return new self($enabled, $databasePath);
    }
}
