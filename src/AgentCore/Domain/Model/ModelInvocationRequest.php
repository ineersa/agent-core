<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Model;

final readonly class ModelInvocationRequest
{
    public function __construct(
        public string $model,
        public ModelInvocationInput $input,
        public ModelInvocationOptions $options = new ModelInvocationOptions(),
    ) {
    }
}
