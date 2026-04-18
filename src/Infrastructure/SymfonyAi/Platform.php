<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Tool\PlatformInterface;

/**
 * Stage 00 placeholder for Symfony AI invocation.
 *
 * Real model invocation wiring is intentionally deferred to stage 05.
 */
final readonly class Platform implements PlatformInterface
{
    public function invoke(string $model, array $input, array $options = []): array
    {
        unset($model, $input, $options);

        throw new \RuntimeException('Platform::invoke() is not wired yet. Implement Symfony AI invocation in stage 05.');
    }
}
