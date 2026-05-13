<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Tool\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Tool\ResolvedModel;
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
