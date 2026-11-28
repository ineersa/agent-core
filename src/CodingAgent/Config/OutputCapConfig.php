<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Output cap settings resolved from Hatfield config.
 *
 * Immutable value object. Contains the storage directory for persisted
 * oversized tool output, character caps for code vs doc-like paths,
 * retention duration for stale-file cleanup. Persisted output is scoped by
 * the explicit runtime run ID, never a configured filename prefix.
 *
 * Only character caps are hydrated from tools.output_cap settings. Retention
 * is internal policy; AppConfig derives storage from the canonical cwd.
 */
final readonly class OutputCapConfig
{
    public const string STORAGE_PATH = '.hatfield/tmp/output-cap';

    public function __construct(
        #[SerializedName('path')]
        public string $storageDir = self::STORAGE_PATH,
        public int $defaultCap = 20000,
        public int $docCap = 50000,
        #[SerializedName('retention')]
        public int $retentionSeconds = 86400,
    ) {
    }

    /**
     * DI factory — extract output-cap settings from AppConfig entity.
     *
     * Used by the Symfony container via services.yaml factory definition
     * so that autowired consumers receive the same instance that lives
     * inside AppConfig.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->tools->outputCap;
    }
}
