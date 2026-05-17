<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Model;

use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Symfony\AI\Platform\Message\MessageBag;

interface ModelResolverInterface
{
    /**
     * Resolves the target model using default settings, messages, context, and options.
     */
    public function resolve(
        string $defaultModel,
        MessageBag $messages,
        ModelInvocationInput $input,
        ModelResolutionOptions $options,
    ): ResolvedModel;
}
