<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Config\ForkLevelEnum;
use Ineersa\CodingAgent\Config\ForksConfigDTO;

/**
 * Resolves effective fork runtime configuration.
 *
 * Applies the override precedence:
 *   1. Requested level, or configured defaultLevel, or built-in Middle.
 *   2. If the resolved level has a configured model, use it;
 *      otherwise model = null (session-model fallback).
 */
final class ForkConfigResolver
{
    public function __construct(
        private readonly ForksConfigDTO $forksConfig,
    ) {
    }

    /**
     * Resolve effective configuration for a fork run.
     *
     * @param ForkLevelEnum|null $requestedLevel Level requested by the caller (null = use default)
     */
    public function resolve(?ForkLevelEnum $requestedLevel): ForkResolvedConfigDTO
    {
        $level = $requestedLevel ?? $this->forksConfig->defaultLevel;
        $levelConfig = $this->forksConfig->levelConfig($level);

        return new ForkResolvedConfigDTO(
            level: $level,
            resolvedModel: $levelConfig->model,
            levelConfig: $levelConfig,
        );
    }
}
