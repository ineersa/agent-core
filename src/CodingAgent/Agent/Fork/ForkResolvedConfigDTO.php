<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Config\ForkLevelConfigDTO;
use Ineersa\CodingAgent\Config\ForkLevelEnum;

/**
 * Resolved fork runtime configuration.
 *
 * Returned by {@see ForkConfigResolver::resolve()}.
 * Combines the resolved level, the effective model (null = session model),
 * and the level's full config DTO.
 */
final readonly class ForkResolvedConfigDTO
{
    public function __construct(
        public ForkLevelEnum $level,
        public ?string $resolvedModel,
        public ForkLevelConfigDTO $levelConfig,
    ) {
    }
}
