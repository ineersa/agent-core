<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Model;

use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;

interface ModelResolverInterface
{
    /**
     * Resolves the target model using default settings, context, and options.
     *
     * $hasConversationMessages is true when the outgoing AgentMessage list has
     * any non-system role. Resolvers must not require a full MessageBag.
     */
    public function resolve(
        string $defaultModel,
        bool $hasConversationMessages,
        ModelInvocationInput $input,
        ModelResolutionOptions $options,
    ): ResolvedModel;
}
