<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Message\MessageBag;
use Ineersa\AgentCore\Domain\Tool\ModelResolutionContext;
use Ineersa\AgentCore\Domain\Tool\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Tool\ResolvedModel;

interface ModelResolverInterface
{
    /**
     * Resolves the target model using default settings, messages, context, and options.
     */
    public function resolve(
        string $defaultModel,
        MessageBag $messages,
        ModelResolutionContext $context,
        ModelResolutionOptions $options,
    ): ResolvedModel;
}
