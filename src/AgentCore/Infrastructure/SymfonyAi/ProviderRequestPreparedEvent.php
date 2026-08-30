<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Internal observation point for a fully prepared, provider-facing request.
 */
final class ProviderRequestPreparedEvent extends Event
{
    /**
     * @param MessageBag|array<string, mixed> $input
     * @param array<string, mixed>            $options
     */
    public function __construct(
        public readonly ModelInvocationInput $invocationInput,
        public readonly ResolvedModel $resolvedModel,
        public readonly string $model,
        public readonly MessageBag|array $input,
        public readonly array $options,
    ) {
    }
}
