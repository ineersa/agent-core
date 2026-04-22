<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Message\MessageBag;
use Ineersa\AgentCore\Domain\Tool\ResolvedModel;

/**
 * Resolves the target AI model configuration from message context and default settings.
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
