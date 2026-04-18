<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Extension;

use Ineersa\AgentCore\Domain\Event\BoundaryHookName;

interface HookSubscriberInterface
{
    /**
     * @return list<string> Boundary hook names from {@see BoundaryHookName::ALL}
     */
    public static function subscribedHooks(): array;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function handle(string $hookName, array $context): array;
}
