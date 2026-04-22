<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Extension;

use Ineersa\AgentCore\Domain\Event\BoundaryHookName;

interface HookSubscriberInterface
{
    /**
     * Returns an array of hook names this subscriber is interested in.
     *
     * @return list<string> Hook names from {@see BoundaryHookName::ALL} or extension hooks with {@see BoundaryHookName::EXTENSION_PREFIX}
     */
    public static function subscribedHooks(): array;

    /**
     * Processes a specific hook event with the provided context and returns the result.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function handle(string $hookName, array $context): array;
}
