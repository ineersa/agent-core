<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Message\MessageBag;
use Ineersa\AgentCore\Domain\Tool\ResolvedModel;

/**
 * Defines the contract for resolving AI model configurations based on incoming message context and default settings. It abstracts the decision logic for selecting the appropriate model to process a given set of messages.
 */
interface ModelResolverInterface
{
    /**
     * Resolves the target model using default settings, messages, context, and options.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     */
    public function resolve(string $defaultModel, MessageBag $messages, array $context = [], array $options = []): ResolvedModel;
}
