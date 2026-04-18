<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Message\MessageBag;
use Ineersa\AgentCore\Domain\Tool\ResolvedModel;

interface ModelResolverInterface
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     */
    public function resolve(string $defaultModel, MessageBag $messages, array $context = [], array $options = []): ResolvedModel;
}
