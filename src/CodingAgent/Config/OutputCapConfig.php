<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Output cap settings resolved from Hatfield config.
 *
 * Immutable value object. Contains the storage directory for persisted
 * oversized tool output, character caps for code vs doc-like paths,
 * retention duration for stale-file cleanup, and an optional session
 * prefix for filename generation.
 *
 * Hydrated from the tools.output_cap section of Hatfield merged config
 * via Symfony Serializer. The storageDir is made absolute by
 * {@see AppConfigLoader::load()} before DTO construction.
 */
final readonly class OutputCapConfig
{
    public function __construct(
        #[SerializedName('path')]
        public string $storageDir = '.hatfield/tmp/output-cap',
        #[SerializedName('default_cap')]
        public int $defaultCap = 20000,
        #[SerializedName('doc_cap')]
        public int $docCap = 50000,
        #[SerializedName('retention')]
        public int $retentionSeconds = 86400,
        #[SerializedName('session_prefix')]
        public ?string $sessionPrefix = null,
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
