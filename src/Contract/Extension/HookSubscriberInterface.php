<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Extension;

use Ineersa\AgentCore\Domain\Event\BoundaryHookName;

/**
 * Defines the contract for components that subscribe to and process specific system hooks within the AgentCore extension system. Implementations declare their target hook names and provide logic to process incoming hook events with associated context data.
 */
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
