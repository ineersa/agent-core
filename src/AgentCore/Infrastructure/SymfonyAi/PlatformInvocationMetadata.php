<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;

final readonly class PlatformInvocationMetadata
{
    public const string OPTION_KEY = '_agent_core_invocation';

    public function __construct(
        public ModelInvocationInput $input,
        public CancellationTokenInterface $cancelToken,
        /**
         * The model identity already resolved for this invocation.
         *
         * Set by LlmPlatformAdapter (single resolution per provider call).
         * ModelResolverRoutingSubscriber reuses it instead of re-resolving
         * mutable session/default state at the ModelRoutingEvent boundary.
         * Null when the invocation did not go through the adapter.
         */
        public ?ResolvedModel $resolvedModel = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function inject(array $options, self $metadata): array
    {
        return array_replace($options, [self::OPTION_KEY => $metadata]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function extract(array $options): ?self
    {
        $metadata = $options[self::OPTION_KEY] ?? null;

        return $metadata instanceof self ? $metadata : null;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function strip(array $options): array
    {
        unset($options[self::OPTION_KEY]);

        return $options;
    }
}
