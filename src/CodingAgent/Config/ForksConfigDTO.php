<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Fork tool defaults (model/thinking only). Not applied globally to parent sessions.
 */
final readonly class ForksConfigDTO
{
    public function __construct(
        public ?string $model = null,
        #[SerializedName('thinking_level')]
        public ?string $thinkingLevel = null,
    ) {
    }

    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->forks;
    }
}
