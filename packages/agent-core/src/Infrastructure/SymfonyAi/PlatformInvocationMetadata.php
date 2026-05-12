<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Tool\ModelInvocationInput;

final readonly class PlatformInvocationMetadata
{
    public const string OPTION_KEY = '_agent_core_invocation';

    public function __construct(
        public ModelInvocationInput $input,
        public CancellationTokenInterface $cancelToken,
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
