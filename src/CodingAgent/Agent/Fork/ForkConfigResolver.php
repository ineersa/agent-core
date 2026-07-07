<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Config\ForksConfigDTO;

/**
 * Resolves effective fork runtime configuration from Hatfield settings.
 *
 * When forks.model is unset, resolvedModel is null and the session model is used.
 */
final class ForkConfigResolver
{
    public function __construct(
        private readonly ForksConfigDTO $forksConfig,
    ) {
    }

    public function resolve(): ForkResolvedConfigDTO
    {
        $model = $this->forksConfig->model;
        if (null !== $model && '' === trim($model)) {
            $model = null;
        }

        return new ForkResolvedConfigDTO(
            resolvedModel: $model,
        );
    }
}
